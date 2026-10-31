<?php

namespace App\Services\Insights;

use App\Models\SaleItem;
use App\Models\SaleItemProfitability;
use App\Support\Money\Decimal;

/**
 * Colunas de rentabilidade de UM item no drill-down e no export do BI (PLANO 1.6), lidas do
 * snapshot `sale_item_profitability` (eager-loaded como `profitability`). Venda não paga não tem
 * snapshot: tudo `null`. As de custo só com `$includeCost`.
 */
final class InsightItemProfitability
{
    /** @return array<string, string|null> */
    public static function of(SaleItem $item, bool $includeCost): array
    {
        $row = $item->profitability;
        $values = [
            'net_revenue' => self::value($row, 'net_revenue'),
            'card_fee' => self::value($row, 'card_fee_share'),
            'tax' => self::value($row, 'tax_amount'),
            'header_discount' => self::value($row, 'allocated_header_discount'),
            'courtesy_kind' => $item->courtesy_kind?->value,
        ];

        if (! $includeCost) {
            return $values;
        }

        return $values + [
            'cost' => self::value($row, 'cost_amount'),
            'commission' => self::value($row, 'commission_amount'),
            'contribution_margin' => self::value($row, 'contribution_margin'),
            'margin_percent' => $row === null ? null : ProfitabilityInsightMetrics::percent(Decimal::of((string) $row->contribution_margin), Decimal::of((string) $row->net_revenue)),
        ];
    }

    private static function value(?SaleItemProfitability $row, string $column): ?string
    {
        return $row === null ? null : (string) $row->{$column};
    }
}
