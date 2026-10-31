<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\Sale;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `POST sales/{id}/finalize` (docs/gap-petflow/PLANO.md, 1.4): fecha a venda de saldo R$ 0,00
 * (só brinde/cortesia/retorno) sem recebimento nem caixa. Mesma autorização do recebimento.
 */
class SaleFinalizationController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SaleService $sales,
    ) {}

    public function __invoke(Request $request, int $id): SaleResource
    {
        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('registerReceipt', $sale);

        return (new SaleResource($this->sales->finalizeWithoutPayment($sale, $request->user())))
            ->additional(['message' => 'Venda finalizada.']);
    }
}
