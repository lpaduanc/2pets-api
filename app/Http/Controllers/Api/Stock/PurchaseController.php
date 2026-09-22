<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StorePurchaseRequest;
use App\Http\Resources\Stock\PurchaseResource;
use App\Models\Purchase;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Purchase\NfeXmlImportService;
use App\Services\Purchase\PurchaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Compras (entrada de nota) — contrato docs/gap-simplesvet/06. Regra toda em
 * `PurchaseService`; aqui só escopo, validação e resposta.
 */
class PurchaseController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PurchaseService $purchases,
        private readonly NfeXmlImportService $xmlImport,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $purchases = $this->scope->scopeQuery(Purchase::query(), $request->user())
            ->with('supplier')
            ->withCount('items')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('supplier_id'), fn (Builder $q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('entered_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('entered_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->toString();
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('invoice_number', 'ilike', "%{$term}%")
                        ->orWhereHas('supplier', fn (Builder $s) => $s->where('legal_name', 'ilike', "%{$term}%")->orWhere('trade_name', 'ilike', "%{$term}%"));

                    if (ctype_digit($term)) {
                        $inner->orWhere('code', (int) $term);
                    }
                });
            })
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request, 50))
            ->withQueryString();

        return PurchaseResource::collection($purchases);
    }

    public function show(Request $request, int $id): PurchaseResource
    {
        return new PurchaseResource($this->find($request, $id)->load(Purchase::RESOURCE_RELATIONS));
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $purchase = $this->purchases->create($request->user(), $request->validated());

        return (new PurchaseResource($purchase))
            ->additional(['message' => 'Compra salva como rascunho.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StorePurchaseRequest $request, int $id): PurchaseResource
    {
        return new PurchaseResource($this->purchases->update($this->find($request, $id), $request->user(), $request->validated()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->purchases->delete($this->find($request, $id));

        return response()->json(['message' => 'Rascunho de compra excluído.']);
    }

    public function receive(Request $request, int $id): PurchaseResource
    {
        $purchase = $this->purchases->receive($this->find($request, $id), $request->user());

        return (new PurchaseResource($purchase))->additional(['message' => 'Entrada de estoque efetivada.']);
    }

    public function cancel(Request $request, int $id): PurchaseResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return new PurchaseResource($this->purchases->cancel($this->find($request, $id), $request->user(), $data['reason']));
    }

    /**
     * Sobe o XML da NF-e e devolve o rascunho casado — NÃO grava a compra. O `xml_token`
     * devolvido é anexado no `POST purchases` depois da conferência.
     */
    public function xmlPreview(Request $request): JsonResponse
    {
        $request->validate([
            // `text/xml` e `application/xml` — navegador e Android mandam os dois.
            'xml' => ['required', 'file', 'max:2048', 'mimetypes:text/xml,application/xml,text/plain'],
        ]);

        return response()->json($this->xmlImport->preview($request->file('xml'), $request->user()));
    }

    private function find(Request $request, int $id): Purchase
    {
        return $this->scope->scopeQuery(Purchase::query(), $request->user())->findOrFail($id);
    }
}
