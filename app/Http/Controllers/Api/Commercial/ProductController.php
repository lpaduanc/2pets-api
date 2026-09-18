<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreProductRequest;
use App\Http\Requests\Commercial\UpdateProductRequest;
use App\Http\Resources\Commercial\ProductResource;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\PricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catálogo comercial — contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md.
 *
 * Escopo por organização em `CommercialScopeResolver` (nunca por `where professional_id` solto
 * aqui): dois funcionários da mesma clínica precisam ver o mesmo catálogo, e um vet volante
 * precisa ver só o dele. Preço/markup NUNCA são calculados neste controller — a conversão mora
 * em `PricingService`, que é o único lugar que sabe a fórmula.
 */
class ProductController extends Controller
{
    use PaginatesResults;

    /** A tela de catálogo soma contadores sobre a lista inteira que carregou. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PricingService $pricing,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scopedQuery($request->user())->with(ProductResource::RESOURCE_RELATIONS);

        $this->applyFilters($query, $request);

        $products = $query->orderBy('name')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $this->pricing->resolvePricing(
            $request->validated(),
            $this->resolveGroup($request->user(), $request->integer('product_group_id')),
        );

        $product = Product::create($data + $this->scope->ownershipFor($request->user()));

        return (new ProductResource($product->load(ProductResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Produto cadastrado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): ProductResource
    {
        $product = $this->scopedQuery($request->user())
            ->with(ProductResource::RESOURCE_RELATIONS)
            ->findOrFail($id);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, int $id): ProductResource
    {
        $product = $this->scopedQuery($request->user())->findOrFail($id);
        $data = $request->validated();

        // O custo que a conta usa é o que está CHEGANDO quando informado, e o já gravado
        // quando não — senão editar só o markup reprecificaria a partir de custo zero.
        $data['average_cost'] ??= (float) $product->average_cost;

        $group = $this->resolveGroup(
            $request->user(),
            $data['product_group_id'] ?? $product->product_group_id,
        );

        $product->update($this->pricing->resolvePricing($data, $group));

        return new ProductResource($product->fresh(ProductResource::RESOURCE_RELATIONS));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $product = $this->scopedQuery($request->user())->findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Produto removido.']);
    }

    /**
     * Leitura de código de barras no balcão e na contagem de estoque. Endpoint próprio, e não
     * um filtro do `index`, porque a resposta é um item ou 404 — o PDV precisa saber
     * imediatamente se o bip encontrou algo, sem interpretar uma lista vazia.
     */
    public function lookup(Request $request): ProductResource
    {
        $request->validate(['gtin' => ['required', 'string', 'max:14']]);

        $product = $this->scopedQuery($request->user())
            ->with(ProductResource::RESOURCE_RELATIONS)
            ->where('gtin', $request->string('gtin')->toString())
            ->active()
            ->firstOrFail();

        return new ProductResource($product);
    }

    /**
     * @return Builder<Product>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(Product::query(), $user);
    }

    /**
     * Grupo sempre lido pela query ESCOPADA: sem isto, mandar `product_group_id` de outra
     * clínica herdaria o markup padrão dela (vazamento de dado comercial entre tenants).
     */
    private function resolveGroup(User $user, ?int $groupId): ?ProductGroup
    {
        if ($groupId === null) {
            return null;
        }

        return $this->scope->scopeQuery(ProductGroup::query(), $user)->with('parent')->find($groupId);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->toString();
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'ilike', "%{$term}%")
                        ->orWhere('sku', 'ilike', "%{$term}%")
                        ->orWhere('code', 'ilike', "%{$term}%")
                        ->orWhere('gtin', $term);
                });
            })
            ->when($request->filled('product_group_id'), fn (Builder $q) => $q->where('product_group_id', $request->integer('product_group_id')))
            ->when($request->filled('brand_id'), fn (Builder $q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('purpose'), fn (Builder $q) => $q->where('purpose', $request->string('purpose')->toString()))
            ->when($request->boolean('expired'), fn (Builder $q) => $q->expired())
            ->when($request->boolean('low_stock'), fn (Builder $q) => $q->lowStock())
            ->when($request->boolean('sellable_only'), fn (Builder $q) => $q->active()->where('purpose', 'resale'))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')));
    }
}
