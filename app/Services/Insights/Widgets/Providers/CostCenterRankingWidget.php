<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\Managerial\ManagerialCostCenters;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\ManagerialMonthComparison;
use App\Services\Insights\Widgets\WidgetMath;

/**
 * Centro de custo: top 5 centros por resultado (linha `net_income` da DRE gerencial por centro,
 * 2.4) no mês corrente, do maior para o menor (empate: nome). "Não rateado" não entra no ranking
 * (não é um centro), mas o valor dele vem à parte para a tela não esconder nada. Custo e lucro:
 * `reports.profitability`.
 */
final class CostCenterRankingWidget extends BaseWidgetProvider
{
    private const TOP = 5;

    public function __construct(CommercialScopeResolver $scope, private readonly ManagerialMonthComparison $months)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'cost_center_ranking';
    }

    public function label(): string
    {
        return 'Centros de custo';
    }

    public function description(): string
    {
        return 'Os 5 centros de custo com melhor resultado no mês.';
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
        $centers = collect($this->months->for($context->user, $context->today, breakdown: true)['current']['cost_centers'] ?? [])
            ->map(fn (array $center): array => [
                'cost_center_id' => $center['cost_center']['id'],
                'name' => $center['cost_center']['name'],
                'type' => $center['cost_center']['type'],
                'net_income' => ManagerialMonthComparison::line($center, 'net_income'),
                'net_revenue' => ManagerialMonthComparison::line($center, 'net_revenue'),
            ]);
        $unallocated = $centers->firstWhere('cost_center_id', ManagerialCostCenters::UNALLOCATED);

        return [
            'month' => $context->today->format('Y-m'),
            'items' => WidgetMath::top($centers->reject(fn (array $c): bool => $c['cost_center_id'] === ManagerialCostCenters::UNALLOCATED)->values()->all(), 'net_income', 'name', self::TOP),
            'unallocated_net_income' => $unallocated['net_income'] ?? '0.00',
        ];
    }
}
