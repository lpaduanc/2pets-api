<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\Stock\StockCountResource;
use App\Models\ProductGroup;
use App\Models\StockCount;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Inventário (contagem física) — contrato docs/gap-simplesvet/07. */
class StockCountController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly StockCountService $counts,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $counts = $this->scope->scopeQuery(StockCount::query(), $request->user())
            ->with('responsible:id,name')
            ->withCount(['items', 'items as items_counted_count' => fn ($q) => $q->whereNotNull('counted_quantity')])
            ->orderByDesc('counted_at')
            ->paginate($this->resolvePerPage($request, 30))
            ->withQueryString();

        return StockCountResource::collection($counts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_group_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $groupId = null;

        if (! empty($data['product_group_id'])) {
            $groupId = $this->scope->scopeQuery(ProductGroup::query(), $request->user())->findOrFail($data['product_group_id'])->id;
        }

        $count = $this->counts->open($request->user(), $groupId, $data['notes'] ?? null);

        return (new StockCountResource($this->loadFull($count)))
            ->additional(['message' => 'Contagem aberta.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): StockCountResource
    {
        return new StockCountResource($this->loadFull($this->find($request, $id)));
    }

    public function updateItems(Request $request, int $id): StockCountResource
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.counted_quantity' => ['present', 'nullable', 'integer', 'min:0'],
        ]);

        $count = $this->counts->addCount($this->find($request, $id), $request->user(), $data['items']);

        return new StockCountResource($this->loadFull($count));
    }

    public function close(Request $request, int $id): StockCountResource
    {
        $count = $this->counts->close($this->find($request, $id), $request->user());

        return (new StockCountResource($this->loadFull($count)))->additional(['message' => 'Inventário fechado e ajustes lançados.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $count = $this->find($request, $id);
        abort_unless($count->isOpen(), 422, 'Inventário fechado não pode ser excluído: os ajustes já estão no estoque.');

        $count->delete();

        return response()->json(['message' => 'Contagem descartada.']);
    }

    private function find(Request $request, int $id): StockCount
    {
        return $this->scope->scopeQuery(StockCount::query(), $request->user())->findOrFail($id);
    }

    private function loadFull(StockCount $count): StockCount
    {
        return $count->load(['responsible:id,name', 'items' => fn ($q) => $q->with('product:id,name,gtin,code,unit_of_sale')->orderBy('id')])
            ->loadCount(['items', 'items as items_counted_count' => fn ($q) => $q->whereNotNull('counted_quantity')]);
    }
}
