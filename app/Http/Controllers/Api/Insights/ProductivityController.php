<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\ProductivityQueryRequest;
use App\Services\Insights\ProductivityService;
use Illuminate\Http\JsonResponse;

/**
 * `GET insights/productivity` e `GET me/productivity` — contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md. Autorização "colaborador nunca
 * vê produtividade de outro" mora em `App\Services\Insights\ProductivityAuthorization`, não
 * aqui — controller só delega e devolve.
 */
class ProductivityController extends Controller
{
    public function __construct(private readonly ProductivityService $productivity) {}

    public function index(ProductivityQueryRequest $request): JsonResponse
    {
        $result = $this->productivity->ranking(
            $request->user(),
            $request->toInsightQuery(),
            $request->requestedEmployeeIds(),
        );

        return response()->json($result);
    }

    public function me(ProductivityQueryRequest $request): JsonResponse
    {
        $result = $this->productivity->myProductivity($request->user(), $request->toInsightQuery());

        return response()->json($result);
    }
}
