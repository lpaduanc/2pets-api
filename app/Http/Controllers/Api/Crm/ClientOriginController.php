<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreClientCatalogRequest;
use App\Http\Resources\Crm\ClientOriginResource;
use App\Models\ClientOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * `client-origins` — contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`.
 * Mesmo padrão de `ImmunizationProductController`: catálogo global (`organization_id = null`,
 * seedado, não editável) + custom por organização.
 */
class ClientOriginController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $origins = ClientOrigin::query()
            ->visibleTo($request->user()->activeOrganizationId())
            ->orderBy('name')
            ->get();

        return ClientOriginResource::collection($origins);
    }

    public function store(StoreClientCatalogRequest $request): JsonResponse
    {
        $organizationId = $request->user()->activeOrganizationId();
        abort_if($organizationId === null, 422, 'É preciso ter uma organização ativa para cadastrar uma origem de cliente.');

        $origin = ClientOrigin::create($request->validated() + ['organization_id' => $organizationId]);

        return response()->json(['data' => new ClientOriginResource($origin)], 201);
    }

    public function update(StoreClientCatalogRequest $request, ClientOrigin $clientOrigin): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $clientOrigin);

        $clientOrigin->update($request->validated());

        return response()->json(['data' => new ClientOriginResource($clientOrigin)]);
    }

    public function destroy(Request $request, ClientOrigin $clientOrigin): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $clientOrigin);

        $clientOrigin->delete();

        return response()->json(['message' => 'Origem removida com sucesso.']);
    }
}
