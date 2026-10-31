<?php

namespace App\Services\Insights;

use App\Enums\InsightMetric;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Métricas de rentabilidade do BI (docs/gap-petflow/PLANO.md, 1.6), a partir das somas SQL de
 * `sale_item_profitability` — exatas (BigDecimal), string com 2 casas.
 *
 *  - `margin_percent` = ΣMC / Σreceita (razão das somas, nunca média dos percentuais; seção 3,
 *    item 5), `null` com receita 0;
 *  - `discount_percent` = (Σ desconto da linha + Σ desconto do cabeçalho rateado) / Σ bruto.
 *
 * Sem `reports.profitability`, as métricas de custo (`InsightMetric::costMetrics`) não saem.
 */
final class ProfitabilityInsightMetrics
{
    /** Colunas somadas no SQL (`InsightMetricsAggregator::SQL`) e o nome da métrica. */
    public const SUMS = [
        'net_revenue' => 'net_revenue',
        'card_fees' => 'card_fee_share',
        'taxes' => 'tax_amount',
        'cost' => 'cost_amount',
        'commissions' => 'commission_amount',
        'contribution_margin' => 'contribution_margin',
    ];

    private const PERCENT_SCALE = 2;

    /** @return array<string, string|null> */
    public static function fromRow(object $row, bool $includeCost): array
    {
        $sums = array_map(fn (string $column): Money => Money::of((string) ($row->{'p_'.$column} ?? '0')), self::SUMS);
        $discounts = Decimal::of((string) $row->discounts)->plus(Decimal::of((string) ($row->p_header_discount ?? '0')));

        $metrics = array_map(fn (Money $sum): string => $sum->toString(), $sums) + [
            'margin_percent' => self::percent($sums['contribution_margin']->toBigDecimal(), $sums['net_revenue']->toBigDecimal()),
            'discount_percent' => self::percent($discounts, Decimal::of((string) $row->gross_sales)),
        ];

        return $includeCost ? $metrics : array_diff_key($metrics, array_flip(array_map(fn (InsightMetric $metric): string => $metric->value, InsightMetric::costMetrics())));
    }

    /**
     * Soma das linhas da série (strings exatas do banco) — nunca soma de float arredondado.
     *
     * @param  Collection<int, object>  $rows
     */
    public static function summedRow(Collection $rows): object
    {
        $columns = [...array_map(fn (string $column): string => 'p_'.$column, array_values(self::SUMS)), 'p_header_discount', 'discounts', 'gross_sales'];

        return (object) array_combine($columns, array_map(
            fn (string $column): string => (string) $rows->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus(Decimal::of((string) ($row->{$column} ?? '0'))), BigDecimal::zero()),
            $columns,
        ));
    }

    public static function percent(BigDecimal $part, BigDecimal $base): ?string
    {
        if (! $base->isPositive()) {
            return null;
        }

        return (string) $part->multipliedBy(100)->dividedBy($base, self::PERCENT_SCALE, RoundingMode::HalfUp);
    }
}
