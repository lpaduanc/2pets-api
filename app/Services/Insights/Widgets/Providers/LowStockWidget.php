<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Models\Product;
use App\Services\Insights\Widgets\DashboardWidgetContext;

/**
 * Estoque baixo: produto com `min_stock > 0` e `stock_quantity < min_stock` (só quem controla
 * estoque). Ordem: o mais longe do mínimo em proporção (saldo / mínimo menor primeiro).
 * Domínio estoque: `products.view`.
 */
final class LowStockWidget extends BaseWidgetProvider
{
    private const LIST_LIMIT = 10;

    public function key(): string
    {
        return 'low_stock';
    }

    public function label(): string
    {
        return 'Estoque baixo';
    }

    public function description(): string
    {
        return 'Produtos abaixo do estoque mínimo.';
    }

    public function domain(): string
    {
        return 'stock';
    }

    public function permission(): string
    {
        return 'products.view';
    }

    public function data(DashboardWidgetContext $context): array
    {
        $query = $this->scoped(Product::query(), $context)
            ->where('controls_stock', true)
            ->where('min_stock', '>', 0)
            ->whereColumn('stock_quantity', '<', 'min_stock');

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)->orderByRaw('stock_quantity / min_stock')->orderBy('name')->limit(self::LIST_LIMIT)
                ->get(['id', 'name', 'stock_quantity', 'min_stock', 'unit_of_sale'])
                ->map(fn (Product $product): array => [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'stock_quantity' => (string) $product->stock_quantity,
                    'min_stock' => (string) $product->min_stock,
                    'unit' => $product->unit_of_sale,
                ])->values()->all(),
        ];
    }
}
