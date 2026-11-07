<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\WidgetMath;

/**
 * Insumos mais usados: Σ quantidade dos movimentos `INTERNAL_USE` nos últimos N dias (janela
 * inclusiva terminando hoje, N = `days`, padrão 30), top 5 por quantidade (empate: nome). A
 * quantidade vai com a unidade do produto — ml e unidade não se comparam, o ranking é por item
 * e o front mostra a unidade. Domínio estoque: `products.view` (sem custo).
 */
final class TopSuppliesWidget extends BaseWidgetProvider
{
    private const TOP = 5;

    public function key(): string
    {
        return 'top_supplies';
    }

    public function label(): string
    {
        return 'Insumos mais usados';
    }

    public function description(): string
    {
        return 'Os 5 insumos com mais uso interno no período.';
    }

    public function domain(): string
    {
        return 'stock';
    }

    public function permission(): string
    {
        return 'products.view';
    }

    public function parameters(): array
    {
        return ['days' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365]];
    }

    public function data(DashboardWidgetContext $context): array
    {
        [$from, $to] = WidgetMath::window($context->today, $context->int('days'));
        $rows = StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->whereIn('stock_movements.product_id', $this->scoped(Product::query(), $context)->select('products.id'))
            ->where('stock_movements.type', StockMovementType::INTERNAL_USE->value)
            ->whereBetween('stock_movements.occurred_at', [$from, $to])
            ->groupBy('products.id', 'products.name', 'products.unit_of_sale')
            ->selectRaw('products.id AS product_id, products.name AS product_name, products.unit_of_sale AS unit, SUM(stock_movements.quantity) AS quantity')
            ->toBase()->get()
            ->map(fn ($row): array => ['product_id' => (int) $row->product_id, 'product_name' => $row->product_name, 'unit' => $row->unit, 'quantity' => (string) $row->quantity])
            ->all();

        return ['days' => $context->int('days'), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'items' => WidgetMath::top($rows, 'quantity', 'product_name', self::TOP)];
    }
}
