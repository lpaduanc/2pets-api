<?php

namespace App\Http\Controllers\Api\Finance;

use App\Enums\CostCenterType;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Centros de custo (PLANO 2.4). Leitura para qualquer membro (o cadastro de produto/serviço e o
 * lançamento escolhem um centro); escrita com `chart-of-accounts.manage` (rota). Excluir é
 * soft delete: o rateio e a DRE de lançamentos antigos continuam mostrando o nome.
 */
class CostCenterController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['nullable', 'boolean']]);
        $centers = $this->scoped($request->user())
            ->when($request->has('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->orderBy('name')->get();

        return response()->json(['data' => $centers->map(fn (CostCenter $c): array => $this->present($c))->values(), 'types' => $this->types()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->scoped($request->user())->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeWrite($request->user());
        $center = CostCenter::create($this->validated($request, true) + $this->scope->ownershipFor($request->user()));

        return response()->json(['data' => $this->present($center->fresh()), 'message' => 'Centro de custo cadastrado.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $center = $this->scoped($request->user())->findOrFail($id);
        $this->authorizeWrite($request->user(), $center->organization_id);
        $center->update($this->validated($request, false));

        return response()->json(['data' => $this->present($center->fresh()), 'message' => 'Centro de custo atualizado.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $center = $this->scoped($request->user())->findOrFail($id);
        $this->authorizeWrite($request->user(), $center->organization_id);
        $center->delete();

        return response()->json(['message' => 'Centro de custo removido.']);
    }

    /**
     * O papel Spatie é global (o membro de uma clínica pode ser vet volante em outro lugar): a
     * permissão vale pelo cargo NA organização do centro, como nos outros cadastros de dinheiro.
     */
    private function authorizeWrite(User $user, ?int $organizationId = null): void
    {
        $organizationId ??= $this->scope->primaryOrganizationId($user);
        abort_unless($this->permissions->allowsInOrganization($user, $organizationId, 'chart-of-accounts.manage'), 403, 'Você não tem permissão para gerenciar centros de custo.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'type' => [$required, Rule::enum(CostCenterType::class)],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return Builder<CostCenter> */
    private function scoped(User $user): Builder
    {
        return $this->scope->scopeQuery(CostCenter::query(), $user);
    }

    /** @return array<string, mixed> */
    private function present(CostCenter $center): array
    {
        return ['id' => $center->id, 'name' => $center->name, 'type' => $center->type->value, 'type_label' => $center->type->label(), 'active' => $center->active];
    }

    /** @return list<array{value: string, label: string}> */
    private function types(): array
    {
        return array_map(fn (CostCenterType $t): array => ['value' => $t->value, 'label' => $t->label()], CostCenterType::cases());
    }
}
