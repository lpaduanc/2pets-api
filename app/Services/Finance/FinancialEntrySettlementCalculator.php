<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Models\FinancialEntry;
use Carbon\CarbonImmutable;

/**
 * Os números de uma baixa (parcial ou total) — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md: "multa/juros/desconto são ajuste
 * sobre a mesma linha". Classe pura (sem I/O) para `FinancialEntryService::settle()` não
 * carregar a conta.
 */
final class FinancialEntrySettlementCalculator
{
    private const RECONCILIATION_TOLERANCE = 0.01;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function calculate(FinancialEntry $entry, array $data, ?int $resolvedAccountId): array
    {
        $discount = round((float) ($data['discount'] ?? $entry->discount), 2);
        $fine = round((float) ($data['fine'] ?? $entry->fine), 2);
        $interest = round((float) ($data['interest'] ?? $entry->interest), 2);
        $netAmount = round((float) $entry->amount - $discount + $fine + $interest, 2);
        $paidAmount = round((float) $data['paid_amount'], 2);

        abort_if($paidAmount <= 0, 422, 'O valor pago deve ser maior que zero.');
        abort_if($paidAmount - $netAmount > self::RECONCILIATION_TOLERANCE, 422, 'O valor pago não pode ser maior que o líquido do lançamento.');

        return [
            'account_id' => $resolvedAccountId,
            'discount' => $discount,
            'fine' => $fine,
            'interest' => $interest,
            'net_amount' => $netAmount,
            'paid_amount' => $paidAmount,
            'paid_at' => isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at']) : CarbonImmutable::now(),
            'status' => $paidAmount >= $netAmount - self::RECONCILIATION_TOLERANCE
                ? FinancialEntryStatus::PAID
                : FinancialEntryStatus::PARTIALLY_PAID,
        ];
    }
}
