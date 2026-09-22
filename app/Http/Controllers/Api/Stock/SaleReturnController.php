<?php

namespace App\Http\Controllers\Api\Stock;

use App\Enums\RefundMethod;
use App\Enums\SaleKind;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StoreSaleReturnRequest;
use App\Http\Resources\Stock\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\SaleReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Devoluções de venda — contrato docs/gap-simplesvet/07 (`/v3/comercial/devolucao`). */
class SaleReturnController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SaleReturnService $returns,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $returns = $this->scope->scopeQuery(SaleReturn::query(), $request->user())
            ->with(['sale:id,number', 'user:id,name', 'items.saleItem:id,description'])
            ->when($request->filled('sale_id'), fn ($q) => $q->where('sale_id', $request->integer('sale_id')))
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request, 50))
            ->withQueryString();

        return SaleReturnResource::collection($returns);
    }

    public function show(Request $request, int $id): SaleReturnResource
    {
        return new SaleReturnResource(
            $this->scope->scopeQuery(SaleReturn::query(), $request->user())
                ->with(['sale:id,number', 'user:id,name', 'items.saleItem:id,description'])
                ->findOrFail($id)
        );
    }

    public function store(StoreSaleReturnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->findOrFail($data['sale_id']);

        $return = $this->returns->create(
            $sale,
            $request->user(),
            $data['items'],
            RefundMethod::from($data['refund_method']),
            $data['reason'],
        );

        return (new SaleReturnResource($return))
            ->additional(['message' => 'Devolução registrada.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Localiza a venda pelo número visível para a tela de devolução — com o quanto de cada item
     * ainda pode voltar.
     */
    public function saleLookup(Request $request): JsonResponse
    {
        $request->validate(['number' => ['required', 'integer', 'min:1']]);

        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())
            ->where('kind', SaleKind::SALE->value)
            ->where('number', $request->integer('number'))
            ->with(['items', 'client:id,name'])
            ->firstOrFail();

        return response()->json(['data' => [
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status->value,
            'sold_at' => $sale->sold_at?->toIso8601String(),
            'total' => (float) $sale->total,
            'client' => $sale->client ? ['id' => $sale->client->id, 'name' => $sale->client->name] : null,
            'items' => $sale->items->map(fn (SaleItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'returned_quantity' => $this->returns->returnedQuantity($item->id),
                'unit_price' => round($item->calculateTotal() / max((float) $item->quantity, 1), 2),
            ])->values(),
        ]]);
    }
}
