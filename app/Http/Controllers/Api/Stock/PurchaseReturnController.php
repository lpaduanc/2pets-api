<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StorePurchaseReturnRequest;
use App\Http\Resources\Stock\PurchaseReturnPreviewLineResource;
use App\Http\Resources\Stock\PurchaseReturnResource;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Purchase\PurchaseReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Devolução de compra ao fornecedor — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.6. Só estoque (o efeito financeiro é decisão pendente). Permissão `products.update` (rota):
 * quem mexe no estoque do catálogo.
 */
class PurchaseReturnController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PurchaseReturnService $returns,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $returns = $this->scope->scopeQuery(PurchaseReturn::query(), $request->user())
            ->with(['purchase.supplier', 'user:id,name', 'fiscalDocuments'])
            ->when($request->filled('purchase_id'), fn (Builder $query) => $query->where('purchase_id', $request->integer('purchase_id')))
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request, 50))
            ->withQueryString();

        return PurchaseReturnResource::collection($returns);
    }

    public function show(Request $request, int $id): PurchaseReturnResource
    {
        return new PurchaseReturnResource($this->scope->scopeQuery(PurchaseReturn::query(), $request->user())->with(PurchaseReturn::RESOURCE_RELATIONS)->findOrFail($id));
    }

    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        $purchase = $this->purchase($request, $request->integer('purchase_id'));
        $return = $this->returns->create($purchase, $request->user(), $request->validated('items'), $request->string('reason')->toString());

        return (new PurchaseReturnResource($return))
            ->additional(['message' => 'Devolução ao fornecedor registrada.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Prévia com a mesma conta do salvar; nada é gravado. */
    public function preview(StorePurchaseReturnRequest $request, int $id): AnonymousResourceCollection
    {
        return PurchaseReturnPreviewLineResource::collection($this->returns->preview($this->purchase($request, $id), $request->validated('items')));
    }

    private function purchase(Request $request, int $id): Purchase
    {
        return $this->scope->scopeQuery(Purchase::query(), $request->user())->findOrFail($id);
    }
}
