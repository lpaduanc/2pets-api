<?php

namespace App\Http\Controllers\Api\Finance;

use App\Enums\FinancialNature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancialCategoryRequest;
use App\Http\Resources\Finance\FinancialCategoryResource;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\FinancialCategoryProvisioner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Plano de contas — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Sem `destroy` para categoria do sistema (`is_system`) nem para categoria com lançamento:
 * histórico contábil não se apaga. O resto desativa-se (`active = false`).
 */
class FinancialCategoryController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FinancialCategoryProvisioner $provisioner,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialCategory::class);

        $request->validate([
            'nature' => ['nullable', Rule::enum(FinancialNature::class)],
            'active' => ['nullable', 'boolean'],
        ]);

        $categories = $this->baseQuery($request->user())
            ->when($request->filled('nature'), fn (Builder $q) => $q->where('nature', $request->string('nature')->toString()))
            ->when($request->has('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return FinancialCategoryResource::collection($categories);
    }

    /**
     * Árvore grupo → folhas, para o cadastro (`CategoriesPage.vue`). O total monetário por
     * categoria (a "soma recursiva" do critério de aceite) é responsabilidade da DRE
     * (`IncomeStatementService`), não deste catálogo — aqui é só estrutura.
     */
    public function tree(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialCategory::class);

        $groups = $this->baseQuery($request->user())
            ->active()
            ->whereNull('parent_id')
            ->with(['children' => fn (Builder $q) => $q->active()->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return FinancialCategoryResource::collection($groups);
    }

    public function store(StoreFinancialCategoryRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FinancialCategory::class);
        $this->provisioner->ensureDefaults($request->user());

        $data = $request->validated();
        $this->assertParentInScope($request->user(), $data['parent_id'] ?? null);

        $category = FinancialCategory::create($data + $this->scope->ownershipFor($request->user()));

        return (new FinancialCategoryResource($category))
            ->additional(['message' => 'Categoria cadastrada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreFinancialCategoryRequest $request, int $id): FinancialCategoryResource
    {
        $category = $this->findForUser($request->user(), $id, 'manage');
        $data = $request->validated();
        $this->assertParentInScope($request->user(), $data['parent_id'] ?? null);

        $category->update($data);

        return new FinancialCategoryResource($category->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = $this->findForUser($request->user(), $id, 'manage');

        abort_if($category->is_system, 422, 'Categoria do sistema não pode ser apagada; desative-a.');
        abort_if($category->entries()->exists() || $category->children()->exists(), 422, 'Categoria com lançamento ou subcategoria não pode ser apagada; desative-a.');

        $category->delete();

        return response()->json(['message' => 'Categoria removida.']);
    }

    /** @return Builder<FinancialCategory> */
    private function baseQuery(User $user): Builder
    {
        $this->provisioner->ensureDefaults($user);

        return $this->scope->scopeQuery(FinancialCategory::query(), $user);
    }

    private function findForUser(User $user, int $id, string $ability): FinancialCategory
    {
        $category = $this->baseQuery($user)->findOrFail($id);
        Gate::forUser($user)->authorize($ability, $category);

        return $category;
    }

    private function assertParentInScope(User $user, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $this->baseQuery($user)->find((int) $parentId);
        abort_if($parent === null, 422, 'Categoria-pai não encontrada nesta clínica.');
        abort_unless($parent->isGroup(), 422, 'A categoria-pai precisa ser um grupo.');
    }
}
