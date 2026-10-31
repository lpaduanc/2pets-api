<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\PriceChangeSource;
use App\Enums\StockMovementType;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreProductRequest;
use App\Http\Requests\Commercial\UpdateProductRequest;
use App\Http\Resources\Commercial\ProductResource;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Pricing\ProductPriceHistoryRecorder;
use App\Services\Commercial\PricingService;
use App\Services\Stock\StockService;
use App\Support\Money\Decimal;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly StockService $stock,
        private readonly ProductPriceHistoryRecorder $priceHistory,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $relations = ProductResource::RESOURCE_RELATIONS;
        if ($request->boolean('with_batches')) {
            $relations[] = 'batches';
        }

        $query = $this->scopedQuery($request->user())->with($relations);

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

        // Saldo informado no cadastro entra pelo livro (doc 07) como saldo inicial, nunca
        // gravado direto na coluna — senão o kardex nasceria sem explicar o próprio número.
        $openingBalance = Quantity::of($data['stock_quantity'] ?? 0);
        $data['stock_quantity'] = 0;
        $data['decimal_places_allowed'] ??= Product::defaultDecimalPlacesFor($data['unit_of_sale'] ?? null);

        $product = DB::transaction(function () use ($data, $request, $openingBalance): Product {
            $product = Product::create($data + $this->scope->ownershipFor($request->user()));
            $this->guardQuantityFitsProduct($product, $openingBalance);
            $this->priceHistory->record($product, PriceChangeSource::MANUAL, $request->user());

            if ($openingBalance->isPositive()) {
                $this->stock->in($product, StockMovementType::OPENING_BALANCE, $openingBalance, [
                    'user' => $request->user(),
                    'notes' => 'Saldo informado no cadastro do produto.',
                ]);
            }

            return $product;
        });

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

        $this->guardBatchTrackingInvariant($product, $data);

        // O custo que a conta usa é o que está CHEGANDO quando informado, e o já gravado
        // quando não — senão editar só o markup reprecificaria a partir de custo zero.
        // Custo médio digitado é reavaliação: passa pelo `StockService::revalue`, que só age
        // quando o valor muda de fato e grava o ajuste de custo no kardex. O front manda o
        // médio em todo salvamento — gravar direto reavaliaria o estoque a cada edição de nome.
        $newAverage = array_key_exists('average_cost', $data) ? (string) Decimal::of($data['average_cost']) : null;
        $data['average_cost'] ??= $product->average_cost;

        $group = $this->resolveGroup(
            $request->user(),
            $data['product_group_id'] ?? $product->product_group_id,
        );

        // Editar o saldo no cadastro vira um AJUSTE no livro (doc 07), com quem e quando.
        $targetStock = array_key_exists('stock_quantity', $data) ? Quantity::of($data['stock_quantity']) : null;
        unset($data['stock_quantity']);

        DB::transaction(function () use ($product, $data, $group, $targetStock, $request, $newAverage): void {
            $attributes = $this->pricing->resolvePricing($data, $group);
            unset($attributes['average_cost']);
            $product->update($attributes);
            // Constatado aqui: a reavaliação abaixo salva outra instância e o custo gravado no
            // histórico tem que ser o novo, lido no fim.
            $priceChanged = $product->wasChanged('price');

            if ($targetStock !== null) {
                $this->guardQuantityFitsProduct($product, $targetStock);
            }

            if ($newAverage !== null) {
                $this->stock->revalue($product, $newAverage, [
                    'user' => $request->user(),
                    'notes' => 'Custo médio alterado no cadastro do produto.',
                ]);
            }

            if ($targetStock !== null) {
                $this->stock->adjustTo($product, $targetStock, [
                    'user' => $request->user(),
                    'notes' => 'Saldo corrigido no cadastro do produto.',
                ]);
            }

            if ($priceChanged) {
                $this->priceHistory->record($product->refresh(), PriceChangeSource::MANUAL, $request->user());
            }
        });

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

    private function guardQuantityFitsProduct(Product $product, Quantity $quantity): void
    {
        if (! $product->acceptsQuantity($quantity)) {
            throw ValidationException::withMessages([
                'stock_quantity' => sprintf('Este produto aceita até %d casa(s) decimal(is) de quantidade.', $product->decimal_places_allowed),
            ]);
        }
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
            ->when($request->filled('show_in_price_list'), fn (Builder $q) => $q->where('show_in_price_list', $request->boolean('show_in_price_list')))
            // Seletor clínico (spec produtos-estoque-consolidado item 4): produtos que aplicam
            // a identidade clínica informada, para o vet escolher marca/lote no ato.
            ->when($request->filled('immunization_product_id'), fn (Builder $q) => $q->forImmunizationProduct($request->integer('immunization_product_id')))
            ->when($request->boolean('only_in_stock'), fn (Builder $q) => $q->where('controls_stock', true)->where('stock_quantity', '>', 0))
            ->when($request->boolean('expired'), fn (Builder $q) => $q->expired())
            ->when($request->boolean('low_stock'), fn (Builder $q) => $q->lowStock())
            // Editor da ficha técnica (PLANO 1.3) acha só os insumos com `is_supply=1`.
            ->when($request->has('is_supply'), fn (Builder $q) => $q->where('is_supply', $request->boolean('is_supply')))
            ->when($request->boolean('sellable_only'), fn (Builder $q) => $q->active()->where('purpose', 'resale')->where('is_supply', false))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')));
    }

    /**
     * Regra de negócio 3 da spec de consolidação: todo produto ligado a uma identidade clínica
     * precisa continuar rastreando lote depois do PATCH, mesmo quando só um dos dois campos
     * vier no payload (o outro já valia no cadastro).
     *
     * @param  array<string, mixed>  $data
     */
    private function guardBatchTrackingInvariant(Product $product, array $data): void
    {
        $effective = $product->replicate();
        $effective->immunization_product_id = array_key_exists('immunization_product_id', $data)
            ? $data['immunization_product_id']
            : $product->immunization_product_id;
        $effective->track_batches = array_key_exists('track_batches', $data)
            ? (bool) $data['track_batches']
            : $product->track_batches;

        abort_if(
            $effective->requiresBatchTracking() && ! $effective->track_batches,
            422,
            'Produto ligado a uma vacina/vermífugo do calendário precisa controlar lote.'
        );
    }
}
