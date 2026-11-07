<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\StockValuationService;
use App\Services\Insights\Widgets\WidgetMath;
use App\Support\Money\Money;

/**
 * Custo estocado / Venda estocada: Σ estoque × custo médio e Σ estoque × preço (produto com
 * controle de estoque e saldo positivo, `StockValuationService`). Variação % contra a foto do
 * último dia do mês anterior (`stock_valuation_snapshots`): (atual − anterior) / anterior × 100;
 * `null` sem foto ou com anterior 0. Mostra custo: exige `reports.profitability`.
 */
final class StockValuationWidget extends BaseWidgetProvider
{
    public function __construct(CommercialScopeResolver $scope, private readonly StockValuationService $valuation)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'stock_valuation';
    }

    public function label(): string
    {
        return 'Custo estocado / Venda estocada';
    }

    public function description(): string
    {
        return 'Estoque valorizado a custo médio e a preço de venda, comparado com o fechamento do mês anterior.';
    }

    public function domain(): string
    {
        return 'stock';
    }

    public function permission(): string
    {
        return 'reports.profitability';
    }

    public function data(DashboardWidgetContext $context): array
    {
        $organizationId = $context->organization?->id;
        $current = $this->valuation->current($organizationId, $context->user->id);
        $previous = $this->valuation->previousMonthClose($organizationId, $context->user->id, $context->today);

        return [
            'cost_value' => $current['cost_value'],
            'sale_value' => $current['sale_value'],
            'product_count' => $current['product_count'],
            'previous_date' => $previous?->snapshot_date?->toDateString(),
            'previous_cost_value' => $previous === null ? null : (string) $previous->cost_value,
            'previous_sale_value' => $previous === null ? null : (string) $previous->sale_value,
            'cost_variation_percent' => WidgetMath::variationPercent(Money::of($current['cost_value']), $previous === null ? null : Money::of($previous->cost_value)),
            'sale_variation_percent' => WidgetMath::variationPercent(Money::of($current['sale_value']), $previous === null ? null : Money::of($previous->sale_value)),
        ];
    }
}
