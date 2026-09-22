<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreClientCatalogRequest;
use App\Http\Resources\Crm\ChurnReasonResource;
use App\Models\ChurnReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * `churn-reasons` — contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`.
 * Mesmo padrão de `ClientOriginController`/`ImmunizationProductController`.
 */
class ChurnReasonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $reasons = ChurnReason::query()
            ->visibleTo($request->user()->activeOrganizationId())
            ->orderBy('name')
            ->get();

        return ChurnReasonResource::collection($reasons);
    }

    public function store(StoreClientCatalogRequest $request): JsonResponse
    {
        $organizationId = $request->user()->activeOrganizationId();
        abort_if($organizationId === null, 422, 'É preciso ter uma organização ativa para cadastrar um motivo de perda.');

        $reason = ChurnReason::create($request->validated() + ['organization_id' => $organizationId]);

        return response()->json(['data' => new ChurnReasonResource($reason)], 201);
    }

    public function update(StoreClientCatalogRequest $request, ChurnReason $churnReason): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $churnReason);

        $churnReason->update($request->validated());

        return response()->json(['data' => new ChurnReasonResource($churnReason)]);
    }

    public function destroy(Request $request, ChurnReason $churnReason): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $churnReason);

        $churnReason->delete();

        return response()->json(['message' => 'Motivo removido com sucesso.']);
    }
}
