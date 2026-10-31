<?php

namespace App\Services\Commercial\Profitability;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Services\Commercial\CommissionCalculator;
use App\Services\Commercial\CommissionRuleResolver;
use App\Support\Money\Money;

/**
 * Lê da venda o que a `ProfitabilityCalculator` precisa (PLANO 1.5): totais congelados das
 * linhas, desconto do cabeçalho, taxa absorvida dos recebimentos (`sale_receipts.operator_fee`
 * menos o acréscimo de repasse), alíquota efetiva da organização (1.4; o documento fiscal não
 * guarda imposto por item), quantidades devolvidas e a comissão de cada linha.
 *
 * Comissão: a do fechamento quando a linha já foi fechada (`commission_settlement_items`), senão
 * a do `CommissionCalculator` com a regra vigente — só para linha com responsável (`staff_id`),
 * igual ao fechamento. Nunca negativa: o `CommissionCalculator` garante na fonte.
 */
final class SaleProfitabilityInputs
{
    public function __construct(
        private readonly SaleAllocationService $allocation,
        private readonly CommissionCalculator $commissions,
        private readonly CommissionRuleResolver $rules,
    ) {}

    /** @return array{header_discount: string, card_fee: string, tax_percent: string|null, items: list<array<string, mixed>>} */
    public function for(Sale $sale): array
    {
        $sale->loadMissing(['items.sellable', 'items.commissionSettlementItem', 'receipts', 'organization']);
        $returned = SaleReturnItem::query()->whereIn('sale_item_id', $sale->items->pluck('id'))
            ->groupBy('sale_item_id')->selectRaw('sale_item_id, SUM(quantity) AS quantity')->pluck('quantity', 'sale_item_id');
        $tax = $sale->organization?->effective_sales_tax_rate;

        return [
            'header_discount' => (string) ($sale->discount_amount ?? '0'),
            'card_fee' => $this->allocation->absorbedCardFee($sale)->toString(),
            'tax_percent' => $tax === null ? null : (string) $tax,
            'items' => $sale->items->sortBy('id')->map(fn (SaleItem $item): array => [
                'sale_item_id' => $item->id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'returned_quantity' => (string) ($returned[$item->id] ?? '0'),
                'courtesy_kind' => $item->courtesy_kind?->value,
                'total' => (string) $item->total,
                'unit_cost' => (string) $item->unit_cost,
                'commission' => $this->commissionOf($sale, $item)->toString(),
            ])->values()->all(),
        ];
    }

    private function commissionOf(Sale $sale, SaleItem $item): Money
    {
        if ($item->commissionSettlementItem !== null) {
            return Money::of($item->commissionSettlementItem->commission_amount);
        }

        if ($item->staff_id === null) {
            return Money::zero();
        }

        $item->setRelation('sale', $sale);

        return Money::of($this->commissions->calculate($item, $this->rules->resolve($item))['commission_amount']);
    }
}
