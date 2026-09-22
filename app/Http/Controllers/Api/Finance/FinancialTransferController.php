<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancialTransferRequest;
use App\Http\Resources\Finance\FinancialTransferResource;
use App\Models\FinancialTransfer;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\FinancialTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Transferência entre contas — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 * Nunca aparece em `reports/income-statement`: não é receita nem despesa.
 */
class FinancialTransferController extends Controller
{
    public function __construct(
        private readonly FinancialTransferService $transfers,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialTransfer::class);

        $transfers = $this->scope->scopeQuery(FinancialTransfer::query(), $request->user())
            ->with(FinancialTransferResource::RESOURCE_RELATIONS)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        return FinancialTransferResource::collection($transfers);
    }

    public function store(StoreFinancialTransferRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FinancialTransfer::class);

        $transfer = $this->transfers->create($request->user(), $request->validated());

        return (new FinancialTransferResource($transfer->load(FinancialTransferResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Transferência registrada.'])
            ->response()
            ->setStatusCode(201);
    }
}
