<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Immunization\StoreImmunizationProductRequest;
use App\Http\Requests\Immunization\UpdateImmunizationProductRequest;
use App\Http\Resources\ImmunizationProductResource;
use App\Models\ImmunizationProduct;
use App\Services\Medical\Immunization\ImmunizationCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * `immunization-products` — catálogo unificado de vacina/vermífugo/antiparasitário.
 * Contrato docs/gap-simplesvet/contratos/13-contrato-api.md.
 */
class ImmunizationProductController extends Controller
{
    public function __construct(private readonly ImmunizationCatalogService $catalogService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $products = ImmunizationProduct::with('speciesLinks')
            ->visibleTo($request->user()->activeOrganizationId())
            ->when($request->query('group'), fn ($query, $group) => $query->where('group', $group))
            ->when($request->query('species'), function ($query, $species): void {
                $query->whereHas('speciesLinks', fn ($scoped) => $scoped->where('species', $species));
            })
            ->orderBy('name')
            ->get();

        return ImmunizationProductResource::collection($products);
    }

    public function store(StoreImmunizationProductRequest $request): JsonResponse
    {
        $organizationId = $request->user()->activeOrganizationId();
        abort_if($organizationId === null, 422, 'É preciso ter uma organização ativa para cadastrar um item de catálogo.');

        $product = $this->catalogService->createProduct($request->validated(), $organizationId);

        return response()->json(['data' => new ImmunizationProductResource($product)], 201);
    }

    public function show(Request $request, ImmunizationProduct $immunizationProduct): ImmunizationProductResource
    {
        Gate::forUser($request->user())->authorize('view', $immunizationProduct);

        return new ImmunizationProductResource($immunizationProduct->load('speciesLinks'));
    }

    public function update(UpdateImmunizationProductRequest $request, ImmunizationProduct $immunizationProduct): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $immunizationProduct);

        $product = $this->catalogService->updateProduct($immunizationProduct, $request->validated());

        return response()->json(['data' => new ImmunizationProductResource($product)]);
    }

    public function destroy(Request $request, ImmunizationProduct $immunizationProduct): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $immunizationProduct);

        $immunizationProduct->delete();

        return response()->json(['message' => 'Item de catálogo removido com sucesso.']);
    }
}
