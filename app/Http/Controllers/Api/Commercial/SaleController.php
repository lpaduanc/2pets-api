<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\DiscountType;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreSaleItemRequest;
use App\Http\Requests\Commercial\StoreSaleReceiptRequest;
use App\Http\Requests\Commercial\StoreSaleRequest;
use App\Http\Resources\Commercial\SaleItemResource;
use App\Http\Resources\Commercial\SaleReceiptResource;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\SaleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Vendas e orçamentos — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Toda regra de negócio está em `SaleService`; toda autorização em `SalePolicy`. O `index`
 * implementa os filtros da "Consulta de vendas" do documento (status, funcionário, tipo de
 * item, pendência fiscal).
 */
class SaleController extends Controller
{
    use PaginatesResults;

    private const DEFAULT_PER_PAGE = 50;

    public function __construct(
        private readonly SaleService $sales,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scopedQuery($request->user())->with(Sale::RESOURCE_RELATIONS);

        $this->applyFilters($query, $request);

        $sales = $query->orderByDesc('created_at')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE))
            ->withQueryString();

        return SaleResource::collection($sales);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->user(), $request->validated());

        return (new SaleResource($sale->load(Sale::RESOURCE_RELATIONS)))
            ->additional(['message' => $sale->isQuote() ? 'Orçamento criado.' : 'Venda iniciada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): SaleResource
    {
        return new SaleResource($this->findForUser($request->user(), $id, 'view'));
    }

    public function storeItem(StoreSaleItemRequest $request, int $id): JsonResponse
    {
        $sale = $this->findForUser($request->user(), $id, 'update');
        $item = $this->sales->addItem($sale, $request->validated());

        return (new SaleItemResource($item))
            ->additional([
                'message' => 'Item adicionado.',
                // O totalizador do PDV é fixo na tela e precisa do novo total a cada item;
                // devolver junto evita um GET extra por clique no balcão.
                'sale' => new SaleResource($sale->fresh(Sale::RESOURCE_RELATIONS)),
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function destroyItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $sale = $this->findForUser($request->user(), $id, 'update');
        $this->sales->removeItem($sale, $itemId);

        return response()->json([
            'message' => 'Item removido.',
            'sale' => new SaleResource($sale->fresh(Sale::RESOURCE_RELATIONS)),
        ]);
    }

    public function applyDiscount(Request $request, int $id): SaleResource
    {
        $request->validate([
            'discount_type' => ['required', \Illuminate\Validation\Rule::enum(DiscountType::class)],
            'discount_value' => ['required_unless:discount_type,none', 'nullable', 'numeric', 'min:0'],
        ]);

        $sale = $this->findForUser($request->user(), $id, 'update');

        return new SaleResource($this->sales->applyDiscount(
            $sale,
            DiscountType::from($request->string('discount_type')->toString()),
            (float) $request->input('discount_value', 0),
        ));
    }

    public function storeReceipt(StoreSaleReceiptRequest $request, int $id): JsonResponse
    {
        $sale = $this->findForUser($request->user(), $id, 'registerReceipt');
        $receipt = $this->sales->registerReceipt($sale, $request->user(), $request->validated());

        return (new SaleReceiptResource($receipt))
            ->additional([
                'message' => 'Recebimento registrado.',
                'sale' => new SaleResource($sale->fresh(Sale::RESOURCE_RELATIONS)),
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function receipts(Request $request, int $id): AnonymousResourceCollection
    {
        $sale = $this->findForUser($request->user(), $id, 'view');

        return SaleReceiptResource::collection($sale->receipts()->with('paymentMethod')->get());
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        $quote = $this->findForUser($request->user(), $id, 'update');
        $sale = $this->sales->convertQuoteToSale($quote, $request->user());

        return (new SaleResource($sale))
            ->additional(['message' => 'Orçamento convertido em venda.'])
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(Request $request, int $id): SaleResource
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $sale = $this->findForUser($request->user(), $id, 'cancel');

        return new SaleResource(
            $this->sales->cancel($sale, $request->user(), $request->string('reason')->toString())
        );
    }

    private function findForUser(User $user, int $id, string $ability): Sale
    {
        $sale = $this->scopedQuery($user)->with(Sale::RESOURCE_RELATIONS)->findOrFail($id);

        Gate::forUser($user)->authorize($ability, $sale);

        return $sale;
    }

    /**
     * @return Builder<Sale>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(Sale::query(), $user);
    }

    /**
     * Filtros da "Consulta de vendas" do doc 01.
     *
     * @param  Builder<Sale>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('kind'), fn (Builder $q) => $q->where('kind', $request->string('kind')->toString()))
            ->when($request->filled('status'), fn (Builder $q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('client_id'), fn (Builder $q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('cash_register_id'), fn (Builder $q) => $q->where('cash_register_id', $request->integer('cash_register_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('to')))
            // "Funcionário" da consulta é o responsável por ALGUM item, não quem operou o
            // caixa: é assim que o SimplesVet monta a coluna e é o que a comissão do doc 09
            // enxerga.
            ->when($request->filled('staff_id'), fn (Builder $q) => $q->whereHas(
                'items',
                fn (Builder $items) => $items->where('staff_id', $request->integer('staff_id'))
            ))
            // "Contém produtos" / "contém serviços" — filtro por tipo de item do documento.
            ->when($request->filled('item_type'), function (Builder $q) use ($request): void {
                $class = $request->string('item_type')->toString() === 'product'
                    ? \App\Models\Product::class
                    : \App\Models\Service::class;

                $q->whereHas('items', fn (Builder $items) => $items->where('sellable_type', $class));
            })
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->toString();
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('number', $term)
                        ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'ilike', "%{$term}%"));
                });
            });
    }
}
