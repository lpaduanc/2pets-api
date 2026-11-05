<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\Sale;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\SaleCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Concluir venda" do PDV (`POST sales/{id}/complete`): quitada → `paid`; com saldo → a prazo
 * (fiado), com a trava do doc 11. Mesma autorização do recebimento.
 */
class SaleCompletionController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SaleCompletionService $completion,
    ) {}

    public function __invoke(Request $request, int $id): SaleResource
    {
        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('registerReceipt', $sale);

        return (new SaleResource($this->completion->complete($sale, $request->user())))
            ->additional(['message' => 'Venda concluída.']);
    }
}
