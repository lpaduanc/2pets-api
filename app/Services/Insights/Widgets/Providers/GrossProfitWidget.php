<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\ManagerialMonthComparison;
use App\Services\Insights\Widgets\WidgetMath;
use App\Support\Money\Money;

/**
 * Lucro bruto: a linha `gross_profit` da DRE gerencial (1.7) do mês corrente, comparada com a do mês
 * anterior — variação % = (atual − anterior) / |anterior| × 100, `null` com anterior 0
 * (`ManagerialMonthComparison`, o service da DRE). Custo e lucro: `reports.profitability`.
 */
final class GrossProfitWidget extends BaseWidgetProvider
{
    public function __construct(CommercialScopeResolver $scope, private readonly ManagerialMonthComparison $months)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'gross_profit';
    }

    public function label(): string
    {
        return 'Lucro bruto';
    }

    public function description(): string
    {
        return 'Lucro bruto do mês (receita líquida − CMV/CSP), comparado com o mês anterior.';
    }

    public function domain(): string
    {
        return 'profit';
    }

    public function permission(): string
    {
        return 'reports.profitability';
    }

    public function data(DashboardWidgetContext $context): array
    {
        ['current' => $current, 'previous' => $previous] = $this->months->for($context->user, $context->today);
        $now = ManagerialMonthComparison::line($current, 'gross_profit');
        $before = ManagerialMonthComparison::line($previous, 'gross_profit');

        return [
            'month' => $context->today->format('Y-m'),
            'value' => $now,
            'previous_month' => $context->today->startOfMonth()->subMonthNoOverflow()->format('Y-m'),
            'previous_value' => $before,
            'variation_percent' => WidgetMath::variationPercent(Money::of($now), Money::of($before)),
            // Margem da própria linha (lucro bruto ÷ receita líquida), não a MC% da DRE.
            'margin_percent' => ManagerialMonthComparison::marginOf($current, 'gross_profit'),
        ];
    }
}
