<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\ManagerialMonthComparison;
use App\Services\Insights\Widgets\WidgetMath;
use App\Support\Money\Money;

/**
 * Ponto de equilíbrio: o `break_even` da DRE gerencial (1.7, `BreakEvenCalculator` = despesas
 * fixas ÷ MC%) do mês corrente, comparado com o do mês anterior; `null` quando a MC não é
 * positiva (o motivo vem em `reason`). Mostra também a receita do mês, para o front desenhar
 * "quanto falta para empatar". Custo e lucro: `reports.profitability`.
 */
final class BreakEvenWidget extends BaseWidgetProvider
{
    public function __construct(CommercialScopeResolver $scope, private readonly ManagerialMonthComparison $months)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'break_even';
    }

    public function label(): string
    {
        return 'Ponto de equilíbrio';
    }

    public function description(): string
    {
        return 'Quanto é preciso vender no mês para cobrir as despesas fixas.';
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
        $now = $current['break_even']['amount'] ?? null;
        $before = $previous['break_even']['amount'] ?? null;
        $revenue = Money::of(ManagerialMonthComparison::line($current, 'gross_revenue'))
            ->minus(ManagerialMonthComparison::line($current, 'discounts'))
            ->minus(ManagerialMonthComparison::line($current, 'returns'));

        return [
            'month' => $context->today->format('Y-m'),
            'value' => $now,
            'reason' => $current['break_even']['reason'] ?? null,
            'sales_revenue' => $revenue->toString(),
            'reached' => $now === null ? null : $revenue->isGreaterThanOrEqualTo($now),
            'previous_value' => $before,
            'variation_percent' => $now === null || $before === null ? null : WidgetMath::variationPercent(Money::of($now), Money::of($before)),
            // A MC% que entra na conta do ponto de equilíbrio.
            'contribution_margin_percent' => $current['margin_percent'],
        ];
    }
}
