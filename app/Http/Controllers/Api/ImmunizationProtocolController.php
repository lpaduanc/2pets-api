<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Immunization\StoreImmunizationProtocolRequest;
use App\Http\Resources\ImmunizationProtocolResource;
use App\Models\ImmunizationProduct;
use App\Models\ImmunizationProtocol;
use App\Services\Medical\Immunization\ImmunizationCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** `immunization-products/{product}/protocols` — contrato docs/gap-simplesvet/contratos/13-contrato-api.md. */
class ImmunizationProtocolController extends Controller
{
    public function __construct(private readonly ImmunizationCatalogService $catalogService) {}

    public function index(Request $request, ImmunizationProduct $immunizationProduct): AnonymousResourceCollection
    {
        $protocols = $immunizationProduct->protocols()
            ->with('doses')
            ->visibleTo($request->user()->activeOrganizationId())
            ->get();

        return ImmunizationProtocolResource::collection($protocols);
    }

    public function store(StoreImmunizationProtocolRequest $request, ImmunizationProduct $immunizationProduct): JsonResponse
    {
        $organizationId = $request->user()->activeOrganizationId();
        abort_if($organizationId === null, 422, 'É preciso ter uma organização ativa para cadastrar um protocolo.');

        $protocol = $this->catalogService->createProtocol($immunizationProduct, $request->validated(), $organizationId);

        return response()->json(['data' => new ImmunizationProtocolResource($protocol)], 201);
    }

    public function show(Request $request, ImmunizationProduct $immunizationProduct, ImmunizationProtocol $protocol): ImmunizationProtocolResource
    {
        Gate::forUser($request->user())->authorize('view', $protocol);

        return new ImmunizationProtocolResource($protocol->load('doses'));
    }

    public function destroy(Request $request, ImmunizationProduct $immunizationProduct, ImmunizationProtocol $protocol): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $protocol);

        $protocol->delete();

        return response()->json(['message' => 'Protocolo removido com sucesso.']);
    }
}
