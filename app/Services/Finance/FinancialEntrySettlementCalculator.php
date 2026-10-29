<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Models\FinancialEntry;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Os números de uma baixa (parcial ou total) — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md: "multa/juros/desconto são ajuste
 * sobre a mesma linha". Classe pura (sem I/O) para `FinancialEntryService::settle()` não
 * carregar a conta.
 */
final class FinancialEntrySettlementCalculator
{
    private const RECONCILIATION_TOLERANCE = '0.01';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function calculate(FinancialEntry $entry, array $data, ?int $resolvedAccountId): array
    {
        $discount = Money::of($data['discount'] ?? $entry->discount);
        $fine = Money::of($data['fine'] ?? $entry->fine);
        $interest = Money::of($data['interest'] ?? $entry->interest);
        $netAmount = Money::of($entry->amount)->minus($discount)->plus($fine)->plus($interest);
        $paidAmount = Money::of($data['paid_amount']);

        abort_if(! $paidAmount->isPositive(), 422, 'O valor pago deve ser maior que zero.');
        abort_if($paidAmount->minus($netAmount)->isGreaterThan(self::RECONCILIATION_TOLERANCE), 422, 'O valor pago não pode ser maior que o líquido do lançamento.');

        return [
            'account_id' => $resolvedAccountId,
            'discount' => $discount->toFloat(),
            'fine' => $fine->toFloat(),
            'interest' => $interest->toFloat(),
            'net_amount' => $netAmount->toFloat(),
            'paid_amount' => $paidAmount->toFloat(),
            'paid_at' => isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at']) : CarbonImmutable::now(),
            'status' => $paidAmount->isGreaterThanOrEqualTo($netAmount->minus(self::RECONCILIATION_TOLERANCE))
                ? FinancialEntryStatus::PAID
                : FinancialEntryStatus::PARTIALLY_PAID,
        ];
    }
}
