<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StorePromotionRequest;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Promotions\PromotionApplier;
use App\Services\Commercial\Promotions\PromotionSimulator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Promoções (PLANO 3.2): CRUD e simulação com `promotions.manage` pelo cargo na organização
 * (`SalePermissionGate`); `POST pdv/cart/evaluate` para quem pode editar a venda — reaplica as
 * promoções vigentes ao carrinho (o mesmo que acontece sozinho a cada item lançado/editado/
 * removido) e devolve o desconto por linha.
 */
class PromotionController extends Controller
{
    private const MANAGE = 'promotions.manage';

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
        private readonly PromotionSimulator $simulator,
        private readonly PromotionApplier $applier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request->user());
        $request->validate(['active' => ['nullable', 'boolean'], 'in_effect' => ['nullable', 'boolean']]);

        $promotions = $this->scoped($request->user())
            ->when($request->has('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->when($request->boolean('in_effect'), fn (Builder $q) => $q->inEffect())
            ->orderByDesc('priority')->orderBy('id')->get();

        return response()->json(['data' => $promotions->map(fn (Promotion $p): array => $this->present($p))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());

        return response()->json(['data' => $this->present($this->scoped($request->user())->findOrFail($id))]);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $request->validated();
        $this->simulator->assertScope($request->user(), $data['type'], $data['config']);
        $promotion = Promotion::create($data + ['created_by' => $request->user()->id] + $this->scope->ownershipFor($request->user()));

        return response()->json(['data' => $this->present($promotion->fresh()), 'message' => 'Promoção cadastrada.'], 201);
    }

    public function update(StorePromotionRequest $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $promotion = $this->scoped($request->user())->findOrFail($id);
        $data = $request->validated();

        if (isset($data['config'])) {
            $this->simulator->assertScope($request->user(), $data['type'] ?? $promotion->type->value, $data['config']);
        }

        $promotion->update($data);

        return response()->json(['data' => $this->present($promotion->fresh()), 'message' => 'Promoção atualizada.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $this->scoped($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Promoção removida.']);
    }

    public function simulate(StorePromotionRequest $request): JsonResponse
    {
        $this->authorizeManage($request->user());

        return response()->json(['data' => $this->simulator->simulate($request->user(), $request->validated())]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $saleId = (int) $request->validate(['sale_id' => ['required', 'integer']])['sale_id'];
        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->findOrFail($saleId);
        Gate::forUser($request->user())->authorize('update', $sale);
        abort_unless($sale->isEditable(), 422, 'A venda não pode mais ser alterada: as promoções ficaram como estavam.');

        $result = $this->applier->apply($sale);
        $sale->recalculateTotals();
        $sale->save();

        return response()->json(['data' => [
            'sale_id' => $sale->id,
            'lines' => collect($result['lines'])->map(fn (array $line, int $itemId): array => ['sale_item_id' => $itemId] + $line)->values(),
            'total_promotion_discount' => $result['total_promotion_discount'],
            'sale_total' => (string) $sale->total,
        ]]);
    }

    private function authorizeManage(User $user): void
    {
        abort_unless($this->permissions->allowsInOrganization($user, $this->scope->primaryOrganizationId($user), self::MANAGE), 403, 'Você não tem permissão para gerenciar promoções.');
    }

    /** @return Builder<Promotion> */
    private function scoped(User $user): Builder
    {
        return $this->scope->scopeQuery(Promotion::query(), $user);
    }

    /** @return array<string, mixed> */
    private function present(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'type' => $promotion->type->value,
            'type_label' => $promotion->type->label(),
            'starts_at' => $promotion->starts_at->toDateString(),
            'ends_at' => $promotion->ends_at?->toDateString(),
            'priority' => $promotion->priority,
            'stackable' => $promotion->stackable,
            'active' => $promotion->active,
            'config' => $promotion->config,
        ];
    }
}
