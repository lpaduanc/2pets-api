<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StorePurchaseOrderRequest;
use App\Http\Resources\Stock\PurchaseOrderResource;
use App\Http\Resources\Stock\PurchaseResource;
use App\Models\PurchaseOrder;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Purchase\PurchaseOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Pedidos de compra — contrato docs/gap-simplesvet/06. */
class PurchaseOrderController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PurchaseOrderService $orders,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->scope->scopeQuery(PurchaseOrder::query(), $request->user())
            ->with(PurchaseOrder::RESOURCE_RELATIONS)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('supplier_id'), fn (Builder $q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request, 50))
            ->withQueryString();

        return PurchaseOrderResource::collection($orders);
    }

    public function show(Request $request, int $id): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->find($request, $id)->load(PurchaseOrder::RESOURCE_RELATIONS));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        return (new PurchaseOrderResource($this->orders->create($request->user(), $request->validated())))
            ->additional(['message' => 'Pedido de compra criado.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StorePurchaseOrderRequest $request, int $id): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->orders->update($this->find($request, $id), $request->user(), $request->validated()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->orders->delete($this->find($request, $id));

        return response()->json(['message' => 'Pedido de compra excluído.']);
    }

    public function send(Request $request, int $id): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->orders->send($this->find($request, $id)));
    }

    public function cancel(Request $request, int $id): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->orders->cancel($this->find($request, $id)));
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        $purchase = $this->orders->receive($this->find($request, $id), $request->user(), $data['items']);

        return (new PurchaseResource($purchase))
            ->additional(['message' => 'Compra em rascunho criada a partir do pedido. Confira e efetive a entrada.'])
            ->response()
            ->setStatusCode(201);
    }

    private function find(Request $request, int $id): PurchaseOrder
    {
        return $this->scope->scopeQuery(PurchaseOrder::query(), $request->user())->findOrFail($id);
    }
}
