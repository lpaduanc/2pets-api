<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Models\FinancialEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Estorno da receita de uma venda cancelada — espelho do `SaleFinancialEntryRecorder`.
 *
 * Para cada lançamento de receita da venda (Produtos, Serviços, Acréscimo de parcelamento), um
 * lançamento com o MESMO valor e sinal negativo, na MESMA categoria, com competência e caixa na
 * data do cancelamento: o mês em que a venda entrou não é reescrito, e o DRE do mês do
 * cancelamento mostra a receita que saiu. Σ original + estorno = 0,00 em cada categoria.
 * Referência `sale_cancellation`/id da venda, idempotente. Venda que não chegou a ser paga não
 * tem receita lançada — nada a estornar.
 */
final class SaleRevenueReversalRecorder
{
    public const REFERENCE_TYPE = 'sale_cancellation';

    public function reverse(Sale $sale, User $user): void
    {
        if ($this->alreadyReversed($sale)) {
            return;
        }

        $originals = FinancialEntry::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('status', '!=', FinancialEntryStatus::CANCELLED->value)
            ->orderBy('id')
            ->get();

        foreach ($originals as $original) {
            $this->storeReversal($sale, $original, $user);
        }
    }

    private function alreadyReversed(Sale $sale): bool
    {
        return FinancialEntry::query()
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $sale->id)
            ->exists();
    }

    private function storeReversal(Sale $sale, FinancialEntry $original, User $user): void
    {
        $today = now()->toDateString();
        $negated = fn (?string $value): string => Money::of($value ?? '0')->negated()->toString();

        FinancialEntry::create([
            'organization_id' => $original->organization_id,
            'professional_id' => $original->professional_id,
            'financial_category_id' => $original->financial_category_id,
            'account_id' => $original->account_id,
            'payment_method_id' => $original->payment_method_id,
            'description' => 'Estorno — '.$original->description,
            'nature' => $original->nature,
            'due_date' => $today,
            'accrual_date' => $today,
            'amount' => $negated($original->amount),
            'net_amount' => $negated($original->net_amount),
            'paid_at' => now(),
            'paid_amount' => $negated($original->paid_amount),
            'status' => FinancialEntryStatus::PAID,
            'reference_type' => self::REFERENCE_TYPE,
            'reference_id' => $sale->id,
            'created_by' => $user->id,
        ]);
    }
}
