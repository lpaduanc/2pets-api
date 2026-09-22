<?php

namespace App\Http\Controllers\Api\Stock;

use App\Enums\StockSituation;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\StockAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Relatórios de estoque — análise de giro e vencimentos (docs/gap-simplesvet/07). */
class StockReportController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly StockAnalysisService $analysis,
    ) {}

    public function analysis(Request $request): JsonResponse
    {
        $request->validate([
            'situation' => ['nullable', Rule::enum(StockSituation::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = $this->analysis->classify($request->user());
        $summary = $this->analysis->summarize($rows);

        $filtered = $rows
            ->when($request->filled('situation'), fn ($c) => $c->where('situation', StockSituation::from($request->string('situation')->toString())))
            ->when($request->filled('search'), function ($c) use ($request) {
                $term = mb_strtolower($request->string('search')->toString());

                return $c->filter(fn (array $row) => str_contains(mb_strtolower($row['product']->name), $term)
                    || str_contains(mb_strtolower((string) $row['product']->code), $term));
            })
            ->sortByDesc('capital')
            ->values();

        $perPage = $this->resolvePerPage($request, 50);
        $page = max(1, $request->integer('page', 1));
        $total = $filtered->count();

        $data = $filtered->slice(($page - 1) * $perPage, $perPage)->map(fn (array $row): array => [
            'product' => [
                'id' => $row['product']->id,
                'name' => $row['product']->name,
                'code' => $row['product']->code,
                'unit_of_sale' => $row['product']->unit_of_sale,
            ],
            'situation' => $row['situation']->value,
            'situation_label' => $row['situation']->label(),
        ] + collect($row)->except(['product', 'situation'])->all())->values();

        return response()->json([
            'summary' => $summary,
            'criteria' => $this->analysis->criteria(),
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Vencidos e a vencer em `days` dias. Produto com lote lista por lote (com saldo);
     * sem lote, usa a validade do cadastro.
     */
    public function expiring(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:365']]);
        $days = $request->integer('days', 30);
        $limit = now()->addDays($days)->endOfDay();
        $today = now()->startOfDay();

        $products = $this->scope->scopeQuery(Product::query(), $request->user())
            ->where('controls_stock', true)
            ->where('is_active', true);

        $batches = ProductBatch::query()
            ->whereIn('product_id', (clone $products)->where('track_batches', true)->select('id'))
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $limit)
            ->with('product:id,name,code,unit_of_sale,average_cost')
            ->get()
            ->map(fn (ProductBatch $batch): array => [
                'product' => ['id' => $batch->product->id, 'name' => $batch->product->name, 'code' => $batch->product->code],
                'batch' => ['id' => $batch->id, 'batch_code' => $batch->batch_code],
                'expires_at' => $batch->expires_at->toDateString(),
                'quantity' => $batch->quantity,
                'days_left' => (int) $today->diffInDays($batch->expires_at, false),
                'capital' => round($batch->quantity * (float) $batch->unit_cost, 2),
            ]);

        $plain = (clone $products)->where('track_batches', false)
            ->where('stock_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $limit)
            ->get(['id', 'name', 'code', 'stock_quantity', 'expiry_date', 'average_cost'])
            ->map(fn (Product $product): array => [
                'product' => ['id' => $product->id, 'name' => $product->name, 'code' => $product->code],
                'batch' => null,
                'expires_at' => $product->expiry_date->toDateString(),
                'quantity' => $product->stock_quantity,
                'days_left' => (int) $today->diffInDays($product->expiry_date, false),
                'capital' => round($product->stock_quantity * (float) $product->average_cost, 2),
            ]);

        $rows = $batches->concat($plain)->sortBy('expires_at')->values();

        return response()->json([
            'data' => $rows,
            'expired_count' => $rows->where('days_left', '<', 0)->count(),
        ]);
    }
}
