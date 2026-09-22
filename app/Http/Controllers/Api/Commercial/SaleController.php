<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\DiscountType;
use App\Enums\FiscalOperation;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreSaleItemRequest;
use App\Http\Requests\Commercial\StoreSaleReceiptRequest;
use App\Http\Requests\Commercial\StoreSaleRequest;
use App\Http\Requests\Commercial\UpdateSaleItemRequest;
use App\Http\Requests\Commercial\UpdateSaleRequest;
use App\Http\Resources\Commercial\SaleItemResource;
use App\Http\Resources\Commercial\SaleReceiptResource;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\SaleService;
use App\Services\Professional\ProfessionalClientsQuery;
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

    /** Teto do catálogo e da busca de clientes do balcão — é autocomplete, não listagem. */
    private const LOOKUP_LIMIT = 50;

    public function __construct(
        private readonly SaleService $sales,
        private readonly CommercialScopeResolver $scope,
        private readonly ProfessionalClientsQuery $clients,
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

    /** "Alterar Cliente" / observações da consulta de vendas (doc 01). */
    public function update(UpdateSaleRequest $request, int $id): SaleResource
    {
        $sale = $this->findForUser($request->user(), $id, 'updateDetails');

        return new SaleResource($this->sales->updateDetails($sale, $request->user(), $request->validated()));
    }

    /**
     * Tudo que o balcão precisa para montar a tela numa chamada só: a equipe (funcionário
     * responsável por item → comissão do doc 09) e os rótulos dos enums, para o app não
     * reimplementar em JavaScript o que o servidor já sabe dizer.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $staff = $this->scope->teamMembers($request->user())
            ->sortBy(fn (OrganizationMember $member): string => mb_strtolower((string) $member->user?->name))
            ->values()
            ->map(fn (OrganizationMember $member): array => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'role' => $member->role?->value,
                'role_label' => $member->role?->label(),
            ]);

        $options = fn (array $cases): array => array_map(
            fn ($case): array => ['value' => $case->value, 'label' => $case->label()],
            $cases,
        );

        return response()->json(['data' => [
            'staff' => $staff,
            'fiscal_operations' => $options(FiscalOperation::cases()),
            'statuses' => $options(SaleStatus::cases()),
            'kinds' => $options(SaleKind::cases()),
        ]]);
    }

    /**
     * Catálogo do balcão: produtos e serviços ATIVOS da clínica numa lista só. Os dois
     * endpoints de cadastro (`products`, `services`) têm escopo e formato diferentes — o de
     * serviços ainda lista só os do próprio usuário —, e a recepção precisa vender o banho
     * cadastrado pela groomer.
     */
    public function catalog(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:product,service'],
        ]);

        $user = $request->user();
        $term = $request->string('search')->trim()->toString();
        $type = $request->string('type')->toString();
        $items = collect();

        if ($type !== 'service') {
            $products = $this->scope->scopeQuery(Product::query(), $user)
                ->active()
                ->with('group:id,name')
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('code', 'ilike', "%{$term}%")
                    ->orWhere('sku', 'ilike', "%{$term}%")
                    ->orWhere('gtin', $term)))
                ->orderBy('name')
                ->limit(self::LOOKUP_LIMIT)
                ->get()
                ->map(fn (Product $product): array => [
                    'type' => 'product',
                    'id' => $product->id,
                    'name' => $product->name,
                    'code' => $product->code ?? $product->sku,
                    'price' => (float) $product->price,
                    'group_name' => $product->group?->name,
                    'stock_quantity' => $product->stock_quantity,
                    'controls_stock' => (bool) $product->controls_stock,
                    'allow_price_override' => (bool) $product->allow_price_override,
                    'commission_percent' => $product->commissionPercent(),
                ]);

            $items = $items->concat($products);
        }

        if ($type !== 'product') {
            $services = $this->scope->scopeQuery(Service::query(), $user)
                ->active()
                ->with('group:id,name')
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('code', 'ilike', "%{$term}%")))
                ->orderBy('name')
                ->limit(self::LOOKUP_LIMIT)
                ->get()
                ->map(fn (Service $service): array => [
                    'type' => 'service',
                    'id' => $service->id,
                    'name' => $service->name,
                    'code' => $service->code,
                    'price' => (float) $service->price,
                    'group_name' => $service->group?->name,
                    'stock_quantity' => null,
                    'controls_stock' => false,
                    'allow_price_override' => (bool) $service->allow_price_override,
                    'commission_percent' => $service->commissionPercent(),
                ]);

            $items = $items->concat($services);
        }

        return response()->json(['data' => $items->values()]);
    }

    /**
     * Busca de cliente do balcão: clientes de QUALQUER pessoa da equipe
     * (`ProfessionalClientsQuery::queryForAny`), por nome, telefone ou CPF. Nunca busca global
     * de usuários — mesmo recorte de privacidade do `GET clients`.
     */
    public function clients(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $term = $request->string('q')->trim()->toString();
        $digits = preg_replace('/\D/', '', $term);

        $clients = $this->clients->queryForAny($this->scope->teamUserIds($request->user()))
            ->with('pets:id,user_id,name,species')
            ->when($term !== '', function (Builder $query) use ($term, $digits): void {
                $query->where(function (Builder $scoped) use ($term, $digits): void {
                    $scoped->where('name', 'ilike', "%{$term}%");

                    if (strlen($digits) >= 3) {
                        $scoped->orWhereRaw("regexp_replace(COALESCE(cpf, ''), '\\D', '', 'g') LIKE ?", ["%{$digits}%"])
                            ->orWhereRaw("regexp_replace(COALESCE(phone, ''), '\\D', '', 'g') LIKE ?", ["%{$digits}%"]);
                    }
                });
            })
            ->orderBy('name')
            ->limit(self::LOOKUP_LIMIT)
            ->get(['id', 'name', 'phone', 'cpf'])
            ->map(fn (User $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'cpf' => $client->cpf,
                'pets' => $client->pets->map(fn ($pet): array => [
                    'id' => $pet->id,
                    'name' => $pet->name,
                    'species' => $pet->species,
                ])->values(),
            ]);

        return response()->json(['data' => $clients]);
    }

    public function storeItem(StoreSaleItemRequest $request, int $id): JsonResponse
    {
        $sale = $this->findForUser($request->user(), $id, 'update');
        $item = $this->sales->addItem($sale, $request->user(), $request->validated());

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

    public function updateItem(UpdateSaleItemRequest $request, int $id, int $itemId): JsonResponse
    {
        $sale = $this->findForUser($request->user(), $id, 'update');
        $item = $this->sales->updateItem($sale, $itemId, $request->validated());

        return (new SaleItemResource($item))
            ->additional([
                'message' => 'Item atualizado.',
                'sale' => new SaleResource($sale->fresh(Sale::RESOURCE_RELATIONS)),
            ])
            ->response();
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

    /**
     * Mantido por compatibilidade com o contrato do doc 01; a conversão de verdade (guardas de
     * status, validade, link público) é do `QuoteService` — mesmo caminho de
     * `POST professional/quotes/{id}/convert`.
     */
    public function convert(Request $request, int $id): JsonResponse
    {
        $quote = $this->findForUser($request->user(), $id, 'manageQuote');
        $sale = app(\App\Services\Commercial\QuoteService::class)->convert($quote, $request->user());

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
            // Pendência de NFC-e / NF-e / NFS-e / "não há pendência" — ver `Sale::scopeFiscalPending`.
            ->when($request->filled('fiscal_pending'), fn (Builder $q) => $q->fiscalPending($request->string('fiscal_pending')->toString()))
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
