<?php

namespace App\Services\Commercial;

use App\Enums\CashMovementType;
use App\Enums\CashRegisterStatus;
use App\Enums\PaymentMethodKind;
use App\Exceptions\Commercial\CashRegisterAlreadyOpenException;
use App\Exceptions\Commercial\CashRegisterClosedException;
use App\Models\CashRegister;
use App\Models\CashRegisterMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida do caixa — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Único lugar que abre, fecha, encerra e lança movimento. Nenhum controller escreve em
 * `cash_registers` ou `cash_register_movements` diretamente: a regra "caixa fechado não aceita
 * movimento" precisa de UM portão, não de uma checagem repetida em cada rota.
 */
final class CashRegisterService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PaymentMethodProvisioner $paymentMethods,
    ) {}

    /**
     * Abre um caixa para o usuário. O suprimento inicial vira um movimento `supply`, e não só
     * a coluna `opening_amount`: assim o extrato do dia começa com a linha "Suprimento
     * inicial" e a soma dos movimentos bate com o esperado sem caso especial.
     */
    public function open(User $user, float $openingAmount = 0, ?string $name = null, ?string $notes = null): CashRegister
    {
        $existing = $this->currentFor($user);

        if ($existing !== null) {
            throw new CashRegisterAlreadyOpenException($existing);
        }

        return DB::transaction(function () use ($user, $openingAmount, $name, $notes): CashRegister {
            try {
                $register = CashRegister::create([
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                    'opening_amount' => round($openingAmount, 2),
                    'status' => CashRegisterStatus::OPEN,
                    'name' => $name ?? 'Caixa de '.$user->name,
                    'notes' => $notes,
                ] + $this->scope->ownershipFor($user));
            } catch (QueryException $exception) {
                // Corrida: dois cliques no botão passam os dois pela checagem acima e só o
                // índice parcial `cash_registers_one_open_per_user` segura o segundo. Traduzimos
                // o 23505 na mesma exceção de negócio, para que o app veja a mesma resposta nos
                // dois caminhos.
                if ($this->isUniqueViolation($exception) && ($current = $this->currentFor($user)) !== null) {
                    throw new CashRegisterAlreadyOpenException($current);
                }

                throw $exception;
            }

            if ($openingAmount > 0) {
                $this->recordMovement(
                    $register,
                    CashMovementType::SUPPLY,
                    $openingAmount,
                    'Suprimento inicial de abertura',
                    $user,
                    cashPaymentMethodId: $this->defaultCashMethodId($user),
                );
            }

            return $register->load('movements');
        });
    }

    /** Caixa aberto do usuário logado — o que o PDV consulta antes de deixar vender. */
    public function currentFor(User $user): ?CashRegister
    {
        return CashRegister::query()
            ->open()
            ->where('opened_by', $user->id)
            ->with(['movements.paymentMethod', 'openedBy'])
            ->first();
    }

    public function addSupply(CashRegister $register, User $user, float $amount, string $description, ?int $paymentMethodId = null, ?int $accountId = null): CashRegisterMovement
    {
        return $this->recordMovement($register, CashMovementType::SUPPLY, $amount, $description, $user, $paymentMethodId ?? $this->defaultCashMethodId($user), $accountId);
    }

    public function addWithdrawal(CashRegister $register, User $user, float $amount, string $description, ?int $paymentMethodId = null, ?int $accountId = null): CashRegisterMovement
    {
        return $this->recordMovement($register, CashMovementType::WITHDRAWAL, $amount, $description, $user, $paymentMethodId ?? $this->defaultCashMethodId($user), $accountId);
    }

    /**
     * Lança um movimento. Portão único da regra "só caixa aberto aceita movimento".
     *
     * `$amount` chega sempre positivo; quem define a direção é o tipo. Ver
     * `App\Enums\CashMovementType::signFor()` e o CHECK da migration.
     */
    public function recordMovement(
        CashRegister $register,
        CashMovementType $type,
        float $amount,
        string $description,
        User $user,
        ?int $cashPaymentMethodId = null,
        ?int $accountId = null,
        ?Model $reference = null,
        ?\DateTimeInterface $occurredAt = null,
    ): CashRegisterMovement {
        $this->assertOpen($register);

        abort_if($amount <= 0, 422, 'O valor do movimento deve ser maior que zero.');

        return $register->movements()->create([
            'type' => $type,
            'payment_method_id' => $cashPaymentMethodId,
            'account_id' => $accountId,
            'amount' => round($amount, 2),
            'occurred_at' => $occurredAt ?? now(),
            'description' => $description,
            'user_id' => $user->id,
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
        ]);
    }

    /**
     * Fecha o caixa com a contagem do operador. CONFERÊNCIA CEGA: o esperado por forma de
     * pagamento é calculado aqui, no servidor, e comparado com o que veio contado — o app
     * nunca recebe o esperado ANTES de enviar a contagem (é a `preview` que o dono usa depois,
     * não o operador antes).
     *
     * @param  array<int|string, float>  $countedByMethod  chave = payment_method_id, ou 'none'
     * @return CashRegister o caixa fechado, com `closing_breakdown` preenchido
     */
    public function close(CashRegister $register, User $user, array $countedByMethod, ?string $notes = null): CashRegister
    {
        $this->assertOpen($register);

        return DB::transaction(function () use ($register, $user, $countedByMethod, $notes): CashRegister {
            $register->load('movements.paymentMethod');

            $expected = $register->expectedByPaymentMethod();
            $breakdown = [];
            $totalExpected = 0.0;
            $totalCounted = 0.0;

            // A união das duas chaves, não só as esperadas: uma forma contada que o sistema não
            // esperava (recebimento lançado em outro caixa por engano) tem que aparecer na
            // conferência, não sumir.
            foreach (array_unique([...array_keys($expected), ...array_keys($countedByMethod)]) as $key) {
                $expectedAmount = round((float) ($expected[$key] ?? 0), 2);
                $countedAmount = round((float) ($countedByMethod[$key] ?? 0), 2);

                $breakdown[$key] = [
                    'expected' => $expectedAmount,
                    'counted' => $countedAmount,
                    'difference' => round($countedAmount - $expectedAmount, 2),
                ];

                $totalExpected += $expectedAmount;
                $totalCounted += $countedAmount;
            }

            $register->update([
                'status' => CashRegisterStatus::CLOSED,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_amount' => round($totalExpected, 2),
                'counted_amount' => round($totalCounted, 2),
                'difference' => round($totalCounted - $totalExpected, 2),
                'closing_breakdown' => $breakdown,
                'notes' => $notes ?? $register->notes,
            ]);

            return $register->fresh(['movements.paymentMethod', 'openedBy', 'closedBy']);
        });
    }

    /**
     * Encerra: o responsável aceitou a diferença. Estado final — daqui não se volta.
     * `CashRegisterPolicy` já garantiu que quem chegou aqui é dono ou o próprio operador.
     */
    public function settle(CashRegister $register, User $user): CashRegister
    {
        abort_unless(
            $register->status->awaitsSettlement(),
            422,
            'Só um caixa fechado ou em revisão pode ser encerrado.'
        );

        $register->update([
            'status' => CashRegisterStatus::SETTLED,
            'settled_by' => $user->id,
            'settled_at' => now(),
        ]);

        return $register->fresh(['openedBy', 'closedBy', 'settledBy']);
    }

    /**
     * Devolve o caixa para revisão. NÃO reabre para movimentos — `under_review` não aceita
     * venda (ver `CashRegisterStatus::acceptsMovements()`). É um pedido de explicação sobre a
     * diferença, não uma reabertura: reabrir permitiria "consertar" a gaveta depois da
     * contagem, que é exatamente o que a conferência existe para impedir.
     */
    public function reopenForReview(CashRegister $register, User $user, string $reason): CashRegister
    {
        abort_unless(
            $register->status === CashRegisterStatus::CLOSED,
            422,
            'Só um caixa fechado pode ir para revisão.'
        );

        $register->update([
            'status' => CashRegisterStatus::UNDER_REVIEW,
            'review_reason' => $reason,
            'settled_by' => null,
            'settled_at' => null,
        ]);

        return $register->fresh(['openedBy', 'closedBy']);
    }

    /**
     * Prévia do fechamento para quem já tem autoridade de conferir (dono). É a MESMA conta do
     * `close()`, sem gravar — serve à tela "conferência" do responsável, nunca ao operador
     * antes de contar (ver a nota de conferência cega em `close()`).
     *
     * @return array<string, mixed>
     */
    public function closingPreview(CashRegister $register): array
    {
        $register->loadMissing('movements.paymentMethod');

        return [
            'expected_by_payment_method' => $register->expectedByPaymentMethod(),
            'expected_cash' => $register->expectedCashAmount(),
            'opening_amount' => (float) $register->opening_amount,
            'total_supplies' => $register->totalSupplies(),
            'total_withdrawals' => $register->totalWithdrawals(),
            'total_sales' => $register->totalSales(),
        ];
    }

    private function assertOpen(CashRegister $register): void
    {
        if (! $register->status->acceptsMovements()) {
            throw new CashRegisterClosedException($register);
        }
    }

    /**
     * A forma "dinheiro" da organização, usada quando suprimento/sangria não informam forma.
     * Suprimento é dinheiro por definição — mas precisa de `payment_method_id` para entrar na
     * coluna certa da conferência.
     */
    private function defaultCashMethodId(User $user): ?int
    {
        return $this->paymentMethods->methodForKind($user, PaymentMethodKind::CASH)?->id;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }
}
