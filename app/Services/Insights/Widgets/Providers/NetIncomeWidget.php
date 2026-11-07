<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\ManagerialMonthComparison;
use App\Services\Insights\Widgets\WidgetMath;
use App\Support\Money\Money;

/**
 * Lucro real: a linha `net_income` da DRE gerencial (1.7) do mês corrente, comparada com a do mês
 * anterior — variação % = (atual − anterior) / |anterior| × 100, `null` com anterior 0
 * (`ManagerialMonthComparison`, o service da DRE). Custo e lucro: `reports.profitability`.
 */
final class NetIncomeWidget extends BaseWidgetProvider
{
    public function __construct(CommercialScopeResolver $scope, private readonly ManagerialMonthComparison $months)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'net_income';
    }

    public function label(): string
    {
        return 'Lucro real';
    }

    public function description(): string
    {
        return 'Lucro líquido do mês pela DRE gerencial, comparado com o mês anterior.';
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
        $now = ManagerialMonthComparison::line($current, 'net_income');
        $before = ManagerialMonthComparison::line($previous, 'net_income');

        return [
            'month' => $context->today->format('Y-m'),
            'value' => $now,
            'previous_month' => $context->today->startOfMonth()->subMonthNoOverflow()->format('Y-m'),
            'previous_value' => $before,
            'variation_percent' => WidgetMath::variationPercent(Money::of($now), Money::of($before)),
            // Margem da própria linha (lucro líquido ÷ receita líquida), não a MC% da DRE.
            'margin_percent' => ManagerialMonthComparison::marginOf($current, 'net_income'),
        ];
    }
}
