<?php

namespace App\Http\Controllers\Api\Stock;

use App\Enums\StockMovementType;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StoreStockMovementRequest;
use App\Http\Resources\Stock\StockMovementResource;
use App\Models\Product;
use App\Models\StockExitReason;
use App\Models\StockMovement;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Kardex e lançamento manual — contrato docs/gap-simplesvet/07. O livro é append-only: não
 * há update nem destroy aqui, e o store só aceita os tipos manuais.
 */
class StockMovementController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly StockService $stock,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scope->scopeQuery(StockMovement::query(), $request->user())
            ->with(StockMovementResource::RESOURCE_RELATIONS)
            ->when($request->filled('product_id'), fn (Builder $q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->whereIn('type', explode(',', $request->string('type')->toString())))
            ->when($request->boolean('other_exits'), fn (Builder $q) => $q->whereIn('type', array_map(
                fn (StockMovementType $t) => $t->value,
                array_filter(StockMovementType::cases(), fn (StockMovementType $t) => $t->isOtherExit())
            )))
            ->when($request->filled('direction'), fn (Builder $q) => $q->where('direction', $request->string('direction')->toString()))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('occurred_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('occurred_at', '<=', $request->date('to')->endOfDay()));

        return StockMovementResource::collection(
            $query->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate($this->resolvePerPage($request, 50))
                ->withQueryString()
        );
    }

    /** Kardex de um produto, mais recente primeiro — o saldo após cada linha vem pronto. */
    public function product(Request $request, int $id): AnonymousResourceCollection
    {
        $product = $this->findProduct($request, $id);
        $request->merge(['product_id' => $product->id]);

        return $this->index($request)->additional([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'stock_quantity' => $product->stock_quantity,
                'average_cost' => (float) $product->average_cost,
                'controls_stock' => $product->controls_stock,
                'track_batches' => $product->track_batches,
            ],
        ]);
    }

    public function batches(Request $request, int $id): JsonResponse
    {
        $product = $this->findProduct($request, $id);

        $batches = $product->batches()
            ->when(! $request->boolean('include_empty'), fn ($q) => $q->where('quantity', '!=', 0))
            ->orderByRaw('expires_at IS NULL, expires_at ASC')
            ->get(['id', 'batch_code', 'expires_at', 'quantity', 'unit_cost'])
            ->map(fn ($batch) => [
                'id' => $batch->id,
                'batch_code' => $batch->batch_code,
                'expires_at' => $batch->expires_at?->toDateString(),
                'quantity' => $batch->quantity,
                'unit_cost' => (float) $batch->unit_cost,
            ]);

        return response()->json(['data' => $batches]);
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $product = $this->findProduct($request, (int) $data['product_id']);

        abort_unless($product->controls_stock, 422, 'Este produto não controla estoque.');

        $type = StockMovementType::from($data['type']);

        if (! empty($data['reason_id'])) {
            $this->scope->scopeQuery(StockExitReason::query(), $request->user())->findOrFail($data['reason_id']);
        }

        if (! empty($data['batch_id'])) {
            $product->batches()->findOrFail($data['batch_id']);
        }

        $context = [
            'unit_cost' => $data['unit_cost'] ?? null,
            'reason_id' => $data['reason_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'batch_code' => $data['batch_code'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'occurred_at' => isset($data['occurred_at']) ? \Carbon\CarbonImmutable::parse($data['occurred_at']) : null,
            'user' => $request->user(),
            'notes' => $data['notes'] ?? null,
        ];

        $movements = $type->direction()->value === 'in'
            ? $this->stock->in($product, $type, (int) $data['quantity'], $context)
            : $this->stock->out($product, $type, (int) $data['quantity'], $context);

        $loaded = StockMovement::with(StockMovementResource::RESOURCE_RELATIONS)
            ->whereKey(array_map(fn (StockMovement $m) => $m->id, $movements))
            ->orderBy('id')
            ->get();

        return StockMovementResource::collection($loaded)
            ->additional([
                'message' => 'Movimento registrado.',
                'stock_quantity' => $product->stock_quantity,
            ])
            ->response()
            ->setStatusCode(201);
    }

    private function findProduct(Request $request, int $id): Product
    {
        return $this->scope->scopeQuery(Product::query(), $request->user())->findOrFail($id);
    }
}
