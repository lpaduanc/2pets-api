<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Resources\Fiscal\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Models\PurchaseReturn;
use App\Models\SaleReturn;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\PurchaseReturnFiscalService;
use App\Services\Fiscal\SaleReturnFiscalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * NF-e de devolução de venda (entrada) e de compra (saída) — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.5/§3.6. `fiscal-documents.issue` na rota + "só OWNER" da policy.
 */
class ReturnFiscalDocumentController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function saleReturn(Request $request, int $id, SaleReturnFiscalService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);
        $document = $service->issue($this->scope->scopeQuery(SaleReturn::query(), $request->user())->findOrFail($id));

        // Devolução só de serviço: não há NF-e de devolução de serviço — nada a emitir, não é erro.
        if ($document === null) {
            return response()->json(['data' => null, 'skipped_reason' => 'service_only', 'message' => 'Devolução só de serviços: não há NF-e de devolução a emitir.']);
        }

        return $this->created($document);
    }

    public function purchaseReturn(Request $request, int $id, PurchaseReturnFiscalService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);

        return $this->created($service->issue($this->scope->scopeQuery(PurchaseReturn::query(), $request->user())->findOrFail($id)));
    }

    private function created(FiscalDocument $document): JsonResponse
    {
        return (new FiscalDocumentResource($document->load(['items', 'referencedDocument'])))
            ->additional(['message' => 'NF-e de devolução emitida.'])
            ->response()
            ->setStatusCode(201);
    }
}
