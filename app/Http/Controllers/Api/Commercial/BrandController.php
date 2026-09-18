<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreBrandRequest;
use App\Http\Resources\Commercial\BrandResource;
use App\Models\Brand;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Marcas — contrato docs/gap-simplesvet/08. Cadastro simples; a razão de existir está no
 * `Brand` (dimensão de BI, doc 20).
 */
class BrandController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $brands = $this->scope->scopeQuery(Brand::query(), $request->user())
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = Brand::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return (new BrandResource($brand))
            ->additional(['message' => 'Marca criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreBrandRequest $request, int $id): BrandResource
    {
        $brand = $this->scope->scopeQuery(Brand::query(), $request->user())->findOrFail($id);
        $brand->update($request->validated());

        return new BrandResource($brand);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $brand = $this->scope->scopeQuery(Brand::query(), $request->user())
            ->withCount('products')
            ->findOrFail($id);

        // Mesma regra do grupo: marca em uso não some sem que alguém decida o que fazer
        // com os produtos que apontam para ela.
        if ($brand->products_count > 0) {
            return response()->json([
                'message' => 'Marca em uso por '.$brand->products_count.' produto(s). Remova o vínculo antes de excluir.',
            ], 422);
        }

        $brand->delete();

        return response()->json(['message' => 'Marca removida.']);
    }
}
