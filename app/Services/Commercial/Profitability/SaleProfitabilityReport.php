<?php

namespace App\Services\Commercial\Profitability;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemProfitability;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Formato do `GET sales/{id}/profitability` (PLANO 1.5) a partir das linhas gravadas — o
 * relatório lê o snapshot e nunca recalcula com o cadastro de hoje (seção 3, item 6). MC% da
 * linha e da venda: razão das somas, 2 casas HALF_UP, `null` sem receita.
 */
final class SaleProfitabilityReport
{
    private const TOTAL_COLUMNS = ['net_revenue', 'cost_amount', 'card_fee_share', 'tax_amount', 'commission_amount', 'contribution_margin'];

    /**
     * @param  Collection<int, SaleItemProfitability>  $rows
     * @return array{status: string, computed_at: string|null, version: int|null, items: list<array<string, mixed>>, totals: array<string, string|null>}
     */
    public function from(Sale $sale, Collection $rows): array
    {
        $items = $sale->items->keyBy('id');
        $lines = $rows->sortBy('sale_item_id')->map(fn (SaleItemProfitability $row): array => $this->line($row, $items->get($row->sale_item_id)))->values()->all();
        $sums = array_combine(self::TOTAL_COLUMNS, array_map(fn (string $column): Money => Money::sum(array_column($lines, $column)), self::TOTAL_COLUMNS));

        return [
            'status' => 'ready',
            'computed_at' => $rows->max('computed_at')?->toIso8601String(),
            'version' => $rows->max('version'),
            'items' => $lines,
            'totals' => array_map(fn (Money $sum): string => $sum->toString(), $sums)
                + ['margin_percent' => self::percentOf($sums['contribution_margin'], $sums['net_revenue'])],
        ];
    }

    /** @return array<string, mixed> */
    private function line(SaleItemProfitability $row, ?SaleItem $item): array
    {
        $money = array_map(fn (string $column): string => (string) $row->{$column}, array_combine(SaleItemProfitability::MONEY_COLUMNS, SaleItemProfitability::MONEY_COLUMNS));

        return [
            'sale_item_id' => $row->sale_item_id,
            'description' => $item?->description,
            'quantity' => (string) $row->quantity,
            'courtesy_kind' => $item?->courtesy_kind?->value,
            ...$money,
            'margin_percent' => self::percentOf(Money::of($row->contribution_margin), Money::of($row->net_revenue)),
        ];
    }

    private static function percentOf(Money $part, Money $base): ?string
    {
        if (! $base->isPositive()) {
            return null;
        }

        return (string) $part->toBigDecimal()->multipliedBy(100)->dividedBy($base->toBigDecimal(), 2, RoundingMode::HalfUp);
    }
}
