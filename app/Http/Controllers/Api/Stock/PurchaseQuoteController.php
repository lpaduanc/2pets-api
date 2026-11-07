<?php

namespace App\Http\Controllers\Api\Stock;

use App\Enums\PurchaseQuoteStatus;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\Stock\PurchaseOrderResource;
use App\Models\PurchaseQuote;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Purchase\Quotes\PurchaseQuoteOrderService;
use App\Services\Purchase\Quotes\PurchaseQuotePresenter;
use App\Services\Purchase\Quotes\PurchaseQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cotação com fornecedores (docs/gap-petflow/PLANO.md, 3.5). Leitura no escopo da organização;
 * escrita com `products.update` pelo cargo na organização (`SalePermissionGate`), a mesma regra
 * de quem gera pedido de compra pela projeção (3.4).
 */
class PurchaseQuoteController extends Controller
{
    use PaginatesResults;

    private const MANAGE = 'products.update';

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
        private readonly PurchaseQuoteService $quotes,
        private readonly PurchaseQuoteOrderService $orders,
        private readonly PurchaseQuotePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::enum(PurchaseQuoteStatus::class)]]);
        $page = $this->scope->scopeQuery(PurchaseQuote::query(), $request->user())
            ->with(PurchaseQuote::RESOURCE_RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request, 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseQuote $quote): array => $this->presenter->quote($quote))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->quote($this->find($request, $id))]);
    }

    /** Comparativo: por item, as ofertas de quem respondeu, com o custo real e o menor marcado. */
    public function comparison(Request $request, int $id): JsonResponse
    {
        return response()->json($this->presenter->comparison($this->find($request, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request->user());
        $quote = $this->quotes->create($request->user(), $this->validated($request, creating: true));

        return response()->json(['data' => $this->presenter->quote($quote)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $quote = $this->quotes->update($this->find($request, $id), $request->user(), $this->validated($request, creating: false));

        return response()->json(['data' => $this->presenter->quote($quote)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $this->quotes->delete($this->find($request, $id));

        return response()->json(null, 204);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $request->validate([
            'quote_supplier_ids' => ['nullable', 'array', 'min:1'],
            'quote_supplier_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return response()->json(['data' => $this->presenter->quote($this->quotes->send($this->find($request, $id), $data['quote_supplier_ids'] ?? null))]);
    }

    public function orders(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $request->validate([
            'winners' => ['required', 'array', 'min:1', 'max:500'],
            'winners.*.quote_item_id' => ['required', 'integer', 'distinct'],
            'winners.*.supplier_id' => ['required', 'integer'],
        ]);
        $quote = $this->find($request, $id);
        $orders = $this->orders->generate($quote, $request->user(), $data['winners']);

        return response()->json([
            'data' => PurchaseOrderResource::collection($orders)->resolve($request),
            'quote' => $this->presenter->quote($quote->fresh(PurchaseQuote::RESOURCE_RELATIONS)),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'valid_until' => ['sometimes', 'date', 'after_or_equal:'.\App\Support\BusinessClock::todayString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:9999999'],
            'supplier_ids' => [$required, 'array', 'min:1', 'max:30'],
            'supplier_ids.*' => ['required', 'integer', 'distinct'],
        ]);
    }

    private function find(Request $request, int $id): PurchaseQuote
    {
        return $this->scope->scopeQuery(PurchaseQuote::query(), $request->user())->with(PurchaseQuote::RESOURCE_RELATIONS)->findOrFail($id);
    }

    private function authorizeManage(User $user): void
    {
        abort_unless($this->permissions->allowsInOrganization($user, $this->scope->primaryOrganizationId($user), self::MANAGE), 403, 'Você não tem permissão para gerenciar cotações nesta organização.');
    }
}
