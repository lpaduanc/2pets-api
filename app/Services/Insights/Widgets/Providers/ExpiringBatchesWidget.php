<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Models\ProductBatch;
use App\Services\Insights\Widgets\DashboardWidgetContext;

/**
 * Produtos / insumos a vencer: lotes com saldo > 0 e validade ≤ hoje + N dias (N = `days`,
 * padrão 30) — inclui os já vencidos com saldo (`days_left` negativo). Ordem: validade mais
 * próxima. Domínio estoque: `products.view`.
 */
final class ExpiringBatchesWidget extends BaseWidgetProvider
{
    private const LIST_LIMIT = 10;

    public function key(): string
    {
        return 'expiring_batches';
    }

    public function label(): string
    {
        return 'Produtos e insumos a vencer';
    }

    public function description(): string
    {
        return 'Lotes com saldo que vencem nos próximos dias.';
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
        $limit = $context->today->addDays($context->int('days'));
        $query = ProductBatch::query()
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->whereIn('products.id', $this->scoped(\App\Models\Product::query(), $context)->select('products.id'))
            ->where('product_batches.quantity', '>', 0)
            ->whereNotNull('product_batches.expires_at')
            ->whereDate('product_batches.expires_at', '<=', $limit->toDateString());

        $rows = (clone $query)->orderBy('product_batches.expires_at')->orderBy('product_batches.id')->limit(self::LIST_LIMIT)
            ->get(['product_batches.id', 'product_batches.batch_code', 'product_batches.expires_at', 'product_batches.quantity', 'products.id AS product_id', 'products.name AS product_name', 'products.is_supply']);

        return [
            'days' => $context->int('days'),
            'count' => (clone $query)->count(),
            'items' => $rows->map(fn ($batch): array => [
                'batch_id' => $batch->id,
                'product_id' => $batch->product_id,
                'product_name' => $batch->product_name,
                'is_supply' => (bool) $batch->is_supply,
                'batch_code' => $batch->batch_code,
                'expires_at' => $batch->expires_at?->toDateString(),
                'days_left' => (int) $context->today->diffInDays($batch->expires_at, false),
                'quantity' => (string) $batch->quantity,
            ])->values()->all(),
        ];
    }
}
