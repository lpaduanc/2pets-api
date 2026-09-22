<?php

namespace App\Services\Finance;

use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Carbon\CarbonImmutable;

/**
 * Transferência entre contas da própria clínica — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md. Nunca entra na DRE: move saldo das
 * duas contas (via `AccountBalanceService`, que soma os `cash_register_movements` — ver nota
 * abaixo), não é receita nem despesa.
 *
 * Hoje a transferência é registrada como documento (`financial_transfers`); o saldo derivado
 * das duas contas envolvidas muda no extrato quando o item 04 also passa a somar esta tabela em
 * `AccountBalanceService` — fora do escopo desta entrega (a spec só pede o registro).
 */
final class FinancialTransferService
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): FinancialTransfer
    {
        $fromAccount = $this->scope->scopeQuery(FinancialAccount::query(), $user)->findOrFail((int) $data['from_account_id']);
        $toAccount = $this->scope->scopeQuery(FinancialAccount::query(), $user)->findOrFail((int) $data['to_account_id']);

        abort_if($fromAccount->id === $toAccount->id, 422, 'A conta de origem e destino não podem ser a mesma.');

        return FinancialTransfer::create([
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => round((float) $data['amount'], 2),
            'occurred_at' => isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : CarbonImmutable::now(),
            'description' => $data['description'] ?? null,
            'created_by' => $user->id,
        ] + $this->scope->ownershipFor($user));
    }
}
