<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CatalogCategory;
use App\Enums\CatalogImageStatus;
use App\Http\Controllers\Controller;
use App\Models\CatalogBrand;
use App\Models\CatalogProduct;
use App\Services\Catalog\CosmosClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestão do catálogo global de produtos pelo admin da plataforma (`/admin/catalog`).
 * Rotas sob `AdminMiddleware`; clínicas não escrevem aqui.
 */
class CatalogProductController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(CatalogCategory::values())],
            'species' => ['nullable', Rule::in(CatalogProduct::SPECIES)],
            'brand_id' => ['nullable', 'integer'],
            'image_status' => ['nullable', Rule::in(CatalogImageStatus::values())],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $products = CatalogProduct::query()
            ->with('brand:id,name')
            ->search($filters['q'] ?? null)
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['species'] ?? null, fn ($q, $v) => $q->where('species', $v))
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->where('catalog_brand_id', $v))
            ->when($filters['image_status'] ?? null, fn ($q, $v) => $q->where('image_status', $v))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json($products);
    }

    public function brands(): JsonResponse
    {
        return response()->json([
            'data' => CatalogBrand::query()->withCount('products')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = CatalogProduct::findOrFail($id);
        $product->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => $product->load('brand:id,name')]);
    }

    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $product = CatalogProduct::findOrFail($id);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']]);

        $file = $request->file('image');
        $path = $file->storeAs('catalog', Str::uuid().'.'.$file->extension(), 'public');
        $product->update([
            'image_path' => '/storage/'.$path,
            'image_status' => CatalogImageStatus::UPLOADED,
            'image_checked_at' => now(),
        ]);

        return response()->json(['data' => $product->load('brand:id,name')]);
    }

    public function fetchImages(Request $request, CosmosClient $cosmos): JsonResponse
    {
        if (! $cosmos->isConfigured()) {
            return response()->json(['message' => 'COSMOS_TOKEN não configurado.', 'code' => 'cosmos_not_configured'], 422);
        }

        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:1000']])['limit'] ?? 200);
        Artisan::call('catalog:fetch-images', ['--limit' => $limit]);

        return response()->json(['message' => trim(Artisan::output())], 202);
    }
}
