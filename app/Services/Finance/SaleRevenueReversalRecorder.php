<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Models\FinancialEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Estorno da receita de uma venda — espelho do `SaleFinancialEntryRecorder`.
 *
 * Por categoria, um lançamento com o valor ATIVO (Σ lançamentos da venda + Σ estornos já feitos)
 * e sinal negativo, na MESMA categoria, com competência e caixa na data do estorno: o mês em que a
 * venda entrou não é reescrito, e o DRE do mês do estorno mostra a receita que saiu. Depois do
 * estorno, Σ de cada categoria = 0,00.
 *
 *  - Cancelamento da venda: referência `sale_cancellation`/id da venda, uma vez só.
 *  - Estorno de um recebimento que tira a venda de "paga" (PLANO 2.3): referência
 *    `sale_payment_reversal`/id da venda. Pode acontecer mais de uma vez na vida da venda (paga,
 *    estornada, paga de novo, estornada de novo): o valor ativo zera a cada vez, então repetir não
 *    estorna o que já foi estornado.
 *
 * Venda sem receita ativa (nunca foi paga, ou já estornada): nada a fazer.
 */
final class SaleRevenueReversalRecorder
{
    public const REFERENCE_TYPE = 'sale_cancellation';

    public const PAYMENT_REVERSAL_REFERENCE_TYPE = 'sale_payment_reversal';

    /** Todo lançamento de receita da venda ou de estorno dela, para o saldo ativo. */
    public const REVENUE_REFERENCES = ['sale', self::REFERENCE_TYPE, self::PAYMENT_REVERSAL_REFERENCE_TYPE];

    public function reverse(Sale $sale, User $user): void
    {
        if (FinancialEntry::query()->where('reference_type', self::REFERENCE_TYPE)->where('reference_id', $sale->id)->exists()) {
            return;
        }

        $this->reverseActive($sale, $user, self::REFERENCE_TYPE, 'Estorno');
    }

    /** A venda deixou de estar paga por um estorno de recebimento (PLANO 2.3). */
    public function reverseForPaymentReversal(Sale $sale, User $user): void
    {
        $this->reverseActive($sale, $user, self::PAYMENT_REVERSAL_REFERENCE_TYPE, 'Estorno de recebimento');
    }

    /** Há receita da venda ainda não estornada? */
    public function hasActiveRevenue(Sale $sale): bool
    {
        return $this->activeByCategory($sale)->isNotEmpty();
    }

    private function reverseActive(Sale $sale, User $user, string $referenceType, string $label): void
    {
        foreach ($this->activeByCategory($sale) as $active) {
            $this->storeReversal($sale, $active['template'], $active['net'], $user, $referenceType, $label);
        }
    }

    /** @return Collection<int, array{template: FinancialEntry, net: Money}> por categoria, só as de saldo ≠ 0 */
    private function activeByCategory(Sale $sale): Collection
    {
        return FinancialEntry::query()
            ->whereIn('reference_type', self::REVENUE_REFERENCES)
            ->where('reference_id', $sale->id)
            ->where('status', '!=', FinancialEntryStatus::CANCELLED->value)
            ->orderBy('id')
            ->get()
            ->groupBy('financial_category_id')
            ->map(fn (Collection $entries): array => [
                'template' => $entries->firstWhere('reference_type', 'sale') ?? $entries->first(),
                'net' => Money::sum($entries->pluck('net_amount')),
            ])
            ->reject(fn (array $active): bool => $active['net']->isZero())
            ->values();
    }

    private function storeReversal(Sale $sale, FinancialEntry $template, Money $active, User $user, string $referenceType, string $label): void
    {
        $today = \App\Support\BusinessClock::todayString();
        $negated = $active->negated()->toString();

        FinancialEntry::create([
            'organization_id' => $template->organization_id,
            'professional_id' => $template->professional_id,
            'financial_category_id' => $template->financial_category_id,
            'account_id' => $template->account_id,
            'payment_method_id' => $template->payment_method_id,
            'description' => $label.' — '.$template->description,
            'nature' => $template->nature,
            'due_date' => $today,
            'accrual_date' => $today,
            'amount' => $negated,
            'net_amount' => $negated,
            'paid_at' => now(),
            'paid_amount' => $negated,
            'status' => FinancialEntryStatus::PAID,
            'reference_type' => $referenceType,
            'reference_id' => $sale->id,
            'created_by' => $user->id,
        ]);
    }
}
