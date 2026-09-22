<?php

namespace App\Services\Commercial;

use App\Enums\ClientAccountEntryDirection;
use App\Enums\ClientAccountEntryType;
use App\Models\ClientAccountEntry;
use App\Models\CompanyClientAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conta corrente do cliente — contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * Único lugar que grava `client_account_entries`/atualiza `company_client_accounts`. Todo
 * lançamento passa por `record()`, que trava a linha (`lockForUpdate`) dentro de uma
 * transação — dois recebimentos simultâneos no mesmo cliente não podem gravar `balance_after`
 * fora de ordem.
 *
 * `array{organization_id: int|null, professional_id: int}` (o `ownershipFor()` de
 * `CommercialScopeResolver`) identifica a CLÍNICA, nunca o cliente — o mesmo tutor tem uma
 * `CompanyClientAccount` por clínica onde é atendido.
 */
final class ClientAccountService
{
    /**
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    public function balanceFor(User $client, array $ownership): float
    {
        return (float) $this->accountFor($client, $ownership)->current_balance;
    }

    /**
     * Saldo + configuração de crédito do cliente nesta clínica — o balcão precisa dos três
     * juntos para decidir se aceita `account_credit`/fiado, sem uma segunda chamada a
     * `account-settings` (achado do frontend: só o `PUT account-settings` devolvia
     * `credit_limit`/`allow_credit_sale`, não havia leitura).
     *
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     * @return array{balance: float, credit_limit: float, allow_credit_sale: bool}
     */
    public function accountSummaryFor(User $client, array $ownership): array
    {
        $account = $this->accountFor($client, $ownership);

        return [
            'balance' => (float) $account->current_balance,
            'credit_limit' => (float) $account->credit_limit,
            'allow_credit_sale' => $account->allow_credit_sale,
        ];
    }

    /**
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     * @return Collection<int, ClientAccountEntry>
     */
    public function statementFor(User $client, array $ownership, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        return $this->accountFor($client, $ownership)->entries()
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Venda que fecha `unpaid` (fiado) — débito pelo valor em aberto. Bloqueia com 422 se o
     * cliente não tem `allow_credit_sale` ou se o fiado estouraria `credit_limit`.
     *
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    public function debitForUnpaidSale(User $client, array $ownership, float $amount, int $saleId, User $actor): ClientAccountEntry
    {
        return $this->record($client, $ownership, function (CompanyClientAccount $account) use ($amount): void {
            abort_if(! $account->allow_credit_sale, 422, 'Este cliente não está habilitado para venda a prazo (fiado).');

            $debtAfter = -(float) $account->current_balance + $amount;
            abort_if(
                $debtAfter - (float) $account->credit_limit > 0.01,
                422,
                sprintf(
                    'Venda a prazo excede o limite de crédito do cliente (limite R$ %s, saldo atual R$ %s).',
                    number_format((float) $account->credit_limit, 2, ',', '.'),
                    number_format((float) $account->current_balance, 2, ',', '.'),
                )
            );
        }, ClientAccountEntryType::SALE_DEBIT, ClientAccountEntryDirection::DEBIT, $amount, $actor, 'sale', $saleId);
    }

    /**
     * Recebimento com forma `account_credit`: o cliente está CONSUMINDO um saldo credor que já
     * tinha — nunca cria saldo negativo além do que a venda já geraria por outro caminho.
     *
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    public function consumeCredit(User $client, array $ownership, float $amount, int $saleId, User $actor): ClientAccountEntry
    {
        return $this->record($client, $ownership, function (CompanyClientAccount $account) use ($amount): void {
            abort_if(
                (float) $account->current_balance - $amount < -0.01,
                422,
                sprintf('Saldo credor insuficiente: disponível R$ %s.', number_format((float) $account->current_balance, 2, ',', '.'))
            );
        }, ClientAccountEntryType::PAYMENT_CREDIT, ClientAccountEntryDirection::DEBIT, $amount, $actor, 'sale', $saleId);
    }

    /** Estorno de `consumeCredit()` — cancelamento de venda cujo recebimento era `account_credit`. */
    public function refundConsumedCredit(User $client, array $ownership, float $amount, int $saleId, User $actor): ClientAccountEntry
    {
        return $this->record($client, $ownership, null, ClientAccountEntryType::REFUND_DEBIT, ClientAccountEntryDirection::CREDIT, $amount, $actor, 'sale', $saleId);
    }

    /**
     * Adiantamento ou ajuste manual — endpoint restrito ao dono do escopo
     * (`CompanyClientAccountPolicy::manage`). `type` só aceita
     * `ClientAccountEntryType::manuallyRecordable()`.
     *
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    public function recordManualEntry(User $client, array $ownership, ClientAccountEntryType $type, float $amount, User $actor, ?string $notes): ClientAccountEntry
    {
        abort_unless(in_array($type, ClientAccountEntryType::manuallyRecordable(), true), 422, 'Tipo de lançamento não permitido neste endpoint.');

        $direction = $type === ClientAccountEntryType::ADJUSTMENT_DEBIT
            ? ClientAccountEntryDirection::DEBIT
            : ClientAccountEntryDirection::CREDIT;

        return $this->record($client, $ownership, null, $type, $direction, $amount, $actor, 'manual', null, $notes);
    }

    /**
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    public function updateSettings(User $client, array $ownership, float $creditLimit, bool $allowCreditSale): CompanyClientAccount
    {
        $account = $this->accountFor($client, $ownership);
        $account->update(['credit_limit' => $creditLimit, 'allow_credit_sale' => $allowCreditSale]);

        return $account->fresh();
    }

    /**
     * Trava a linha, roda a validação de negócio (se houver) e grava o lançamento —
     * `balance_after` sai do MESMO saldo travado, nunca de uma leitura solta.
     *
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     * @param  (callable(CompanyClientAccount): void)|null  $guard
     */
    private function record(
        User $client,
        array $ownership,
        ?callable $guard,
        ClientAccountEntryType $type,
        ClientAccountEntryDirection $direction,
        float $amount,
        User $actor,
        string $referenceType,
        ?int $referenceId,
        ?string $notes = null,
    ): ClientAccountEntry {
        $amount = round($amount, 2);
        abort_if($amount <= 0, 422, 'O valor do lançamento deve ser maior que zero.');

        return DB::transaction(function () use ($client, $ownership, $guard, $type, $direction, $amount, $actor, $referenceType, $referenceId, $notes): ClientAccountEntry {
            $account = $this->lockedAccountFor($client, $ownership);

            if ($guard !== null) {
                $guard($account);
            }

            $balanceAfter = round((float) $account->current_balance + ($amount * $direction->sign()), 2);
            $account->update([
                'current_balance' => $balanceAfter,
                'last_purchase_at' => $type === ClientAccountEntryType::SALE_DEBIT ? now() : $account->last_purchase_at,
            ]);

            return $account->entries()->create([
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'occurred_at' => now(),
                'user_id' => $actor->id,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    private function lockedAccountFor(User $client, array $ownership): CompanyClientAccount
    {
        $this->accountFor($client, $ownership);

        return CompanyClientAccount::query()
            ->where('client_id', $client->id)
            ->when(
                $ownership['organization_id'] !== null,
                fn ($q) => $q->where('organization_id', $ownership['organization_id']),
                fn ($q) => $q->whereNull('organization_id')->where('professional_id', $ownership['professional_id']),
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    private function accountFor(User $client, array $ownership): CompanyClientAccount
    {
        // Diferente do `ownershipFor()` genérico (que sempre preenche `professional_id` com
        // quem criou o registro): aqui `professional_id` só é gravado para o vet volante SEM
        // organização (spec: "dono do vínculo"). Preenchê-lo também quando há organização
        // colidiria com a constraint `unique(professional_id, client_id)` — o mesmo
        // funcionário atendendo o mesmo cliente em DUAS clínicas diferentes criaria um falso
        // conflito de unicidade.
        return CompanyClientAccount::query()->firstOrCreate([
            'organization_id' => $ownership['organization_id'],
            'professional_id' => $ownership['organization_id'] === null ? $ownership['professional_id'] : null,
            'client_id' => $client->id,
        ]);
    }
}
