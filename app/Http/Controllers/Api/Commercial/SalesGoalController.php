<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\SalesGoalScope;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\SalesGoal;
use App\Models\User;
use App\Rules\ScopedCostCenterExists;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Goals\SalesGoalProgressService;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Metas de venda (PLANO 2.5). Ver (lista e andamento) mostra valores de venda: exige
 * `reports.dre.view`; cadastrar/editar/excluir exige `sales-goals.manage` — os dois pelo cargo
 * na organização da meta (`SalePermissionGate`), nunca pelo papel global.
 */
class SalesGoalController extends Controller
{
    private const VIEW = 'reports.dre.view';

    private const MANAGE = 'sales-goals.manage';

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
        private readonly SalesGoalProgressService $progress,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeFor($request->user(), self::VIEW);
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $goals = $this->scoped($request->user())->when($request->filled('month'), fn (Builder $q) => $q->where('month', $request->string('month')->toString()))
            ->orderByDesc('month')->orderBy('scope')->orderBy('id')->get();

        return response()->json(['data' => $goals->map(fn (SalesGoal $goal): array => $this->present($goal))->values()]);
    }

    public function progress(Request $request): JsonResponse
    {
        $this->authorizeFor($request->user(), self::VIEW);
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $goals = $this->scoped($request->user())->where('month', $month)->orderBy('scope')->orderBy('id')->get();

        return response()->json(['data' => $this->progress->progress($request->user(), $this->organization($request->user()), $month, $goals)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeFor($request->user(), self::MANAGE);
        $data = $this->validated($request, null);
        $goal = SalesGoal::create($data + ['created_by' => $request->user()->id] + $this->scope->ownershipFor($request->user()));

        return response()->json(['data' => $this->present($goal->fresh()), 'message' => 'Meta cadastrada.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeFor($request->user(), self::MANAGE);
        $goal = $this->scoped($request->user())->findOrFail($id);
        $goal->update($this->validated($request, $goal));

        return response()->json(['data' => $this->present($goal->fresh()), 'message' => 'Meta atualizada.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeFor($request->user(), self::MANAGE);
        $this->scoped($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Meta removida.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SalesGoal $current): array
    {
        $required = $current === null ? 'required' : 'sometimes';
        $data = $request->validate([
            'month' => [$required, 'date_format:Y-m'],
            'scope' => [$required, Rule::enum(SalesGoalScope::class)],
            'scope_id' => ['nullable', 'integer'],
            'amount' => [$required, 'numeric', 'min:0.01', 'max:999999999.99'],
        ]);

        $merged = $data + ($current === null ? [] : ['month' => $current->month, 'scope' => $current->scope->value, 'scope_id' => $current->scope_id]);
        $scope = SalesGoalScope::from($merged['scope']);
        $merged['scope_id'] = $scope->needsScopeId() ? ($merged['scope_id'] ?? null) : null;
        $this->assertScopeTarget($request->user(), $scope, $merged['scope_id']);
        $this->assertUnique($request->user(), $merged, $current);

        if (isset($data['amount'])) {
            $merged['amount'] = Money::of($data['amount'])->toString();
        }

        return array_intersect_key($merged, array_flip(['month', 'scope', 'scope_id', 'amount']));
    }

    private function assertScopeTarget(User $user, SalesGoalScope $scope, ?int $scopeId): void
    {
        if (! $scope->needsScopeId()) {
            return;
        }

        $fail = fn (string $message) => throw ValidationException::withMessages(['scope_id' => $message]);

        if ($scopeId === null) {
            $fail('Informe o centro de custo ou o profissional da meta.');
        }

        if ($scope === SalesGoalScope::COST_CENTER) {
            (new ScopedCostCenterExists($user))->validate('scope_id', $scopeId, $fail);

            return;
        }

        $organizationId = $this->scope->primaryOrganizationId($user);
        $isMember = $organizationId !== null && OrganizationMember::query()->whereKey($scopeId)->where('organization_id', $organizationId)->exists();
        $isMember || $fail('Profissional não pertence à equipe desta organização.');
    }

    /** @param  array<string, mixed>  $data */
    private function assertUnique(User $user, array $data, ?SalesGoal $current): void
    {
        $exists = $this->scoped($user)->where('month', $data['month'])->where('scope', $data['scope'])
            ->when($data['scope_id'] === null, fn (Builder $q) => $q->whereNull('scope_id'), fn (Builder $q) => $q->where('scope_id', $data['scope_id']))
            ->when($current !== null, fn (Builder $q) => $q->whereKeyNot($current->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['scope' => 'Já existe meta para este mês e escopo.']);
        }
    }

    private function authorizeFor(User $user, string $permission): void
    {
        abort_unless(
            $this->permissions->allowsInOrganization($user, $this->scope->primaryOrganizationId($user), $permission),
            403,
            $permission === self::MANAGE ? 'Você não tem permissão para gerenciar metas de venda.' : 'Você não tem permissão para ver os valores de venda.',
        );
    }

    /** @return Builder<SalesGoal> */
    private function scoped(User $user): Builder
    {
        return $this->scope->scopeQuery(SalesGoal::query(), $user);
    }

    private function organization(User $user): ?Organization
    {
        $id = $this->scope->primaryOrganizationId($user);

        return $id === null ? null : Organization::find($id);
    }

    /** @return array<string, mixed> */
    private function present(SalesGoal $goal): array
    {
        return [
            'id' => $goal->id, 'month' => $goal->month, 'scope' => $goal->scope->value, 'scope_label' => $goal->scope->label(),
            'scope_id' => $goal->scope_id, 'amount' => (string) $goal->amount,
        ];
    }
}
