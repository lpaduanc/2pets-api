<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\SaleItemCourtesyKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\MarkSaleItemCourtesyRequest;
use App\Http\Resources\Commercial\SaleItemResource;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Pricing\SaleItemCourtesyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Brinde/cortesia/retorno na linha da venda ou do orçamento (docs/gap-petflow/PLANO.md, 1.4).
 * Resposta no formato do `PATCH sales/{id}/items/{itemId}`: o item e a venda recalculada.
 */
class SaleItemCourtesyController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SaleItemCourtesyService $courtesy,
    ) {}

    public function update(MarkSaleItemCourtesyRequest $request, int $id, int $itemId): JsonResponse
    {
        $sale = $this->editableSale($request->user(), $id);
        $item = $this->courtesy->mark($sale, $itemId, $request->user(), SaleItemCourtesyKind::from($request->string('kind')->toString()), $request->string('reason')->toString());

        return $this->respond($sale, $item, 'Item marcado como brinde, cortesia ou retorno.');
    }

    public function destroy(Request $request, int $id, int $itemId): JsonResponse
    {
        $sale = $this->editableSale($request->user(), $id);
        $item = $this->courtesy->unmark($sale, $itemId, $request->user());

        return $this->respond($sale, $item, 'Marcação de brinde, cortesia ou retorno removida.');
    }

    private function editableSale(User $user, int $id): Sale
    {
        $sale = $this->scope->scopeQuery(Sale::query(), $user)->findOrFail($id);
        Gate::forUser($user)->authorize('update', $sale);

        return $sale;
    }

    private function respond(Sale $sale, SaleItem $item, string $message): JsonResponse
    {
        return (new SaleItemResource($item))
            ->additional(['message' => $message, 'sale' => new SaleResource($sale->fresh(Sale::RESOURCE_RELATIONS))])
            ->response();
    }
}
