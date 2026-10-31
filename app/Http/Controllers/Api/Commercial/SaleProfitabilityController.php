<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Profitability\SaleProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET sales/{id}/profitability` (docs/gap-petflow/PLANO.md, 1.5): o resultado por item da venda
 * finalizada. Mostra custo — só com `reports.profitability` no cargo da organização da venda
 * (`SalePermissionGate`), senão 403.
 */
class SaleProfitabilityController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
        private readonly SaleProfitabilityService $profitability,
    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->findOrFail($id);
        abort_unless($this->permissions->allows($request->user(), $sale, SalePermissionGate::PROFITABILITY), 403, 'Você não tem permissão para ver a rentabilidade desta venda.');

        return response()->json(['data' => $this->profitability->report($sale)]);
    }
}
