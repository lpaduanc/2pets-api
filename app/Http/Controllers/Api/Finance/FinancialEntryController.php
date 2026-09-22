<?php

namespace App\Http\Controllers\Api\Finance;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SettleFinancialEntryRequest;
use App\Http\Requests\Finance\StoreFinancialEntryRequest;
use App\Http\Requests\Finance\UpdateFinancialEntryRequest;
use App\Http\Resources\Finance\FinancialEntryResource;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\FinancialEntryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Lançamento manual (receita/despesa) — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Toda mutação delega a `FinancialEntryService`; toda autorização passa por
 * `FinancialEntryPolicy` (só `OWNER`, ver spec "Permissões por papel").
 */
class FinancialEntryController extends Controller
{
    private const RELATIONS = ['category', 'account', 'supplier', 'paymentMethod'];

    public function __construct(
        private readonly FinancialEntryService $entries,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'nature' => ['nullable', Rule::enum(FinancialNature::class)],
            'status' => ['nullable', Rule::enum(FinancialEntryStatus::class)],
            'financial_category_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        Gate::forUser($request->user())->authorize('viewAny', FinancialEntry::class);

        $entries = $this->filteredQuery($request)->with(self::RELATIONS)
            ->orderByDesc('due_date')
            ->paginate((int) $request->integer('per_page', 30));

        return FinancialEntryResource::collection($entries);
    }

    public function store(StoreFinancialEntryRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FinancialEntry::class);

        $created = $this->entries->create($request->user(), $request->validated());

        return FinancialEntryResource::collection($created->load(self::RELATIONS))
            ->additional(['message' => 'Lançamento cadastrado.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): FinancialEntryResource
    {
        return new FinancialEntryResource($this->findForUser($request->user(), $id));
    }

    public function update(UpdateFinancialEntryRequest $request, int $id): FinancialEntryResource
    {
        $entry = $this->findForUser($request->user(), $id);

        return new FinancialEntryResource($this->entries->update($entry, $request->user(), $request->validated()));
    }

    public function settle(SettleFinancialEntryRequest $request, int $id): FinancialEntryResource
    {
        $entry = $this->findForUser($request->user(), $id);

        return new FinancialEntryResource($this->entries->settle($entry, $request->user(), $request->validated()));
    }

    public function unsettle(Request $request, int $id): FinancialEntryResource
    {
        $entry = $this->findForUser($request->user(), $id);

        return new FinancialEntryResource($this->entries->unsettle($entry));
    }

    public function updateSeries(Request $request, string $seriesId): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('create', FinancialEntry::class);

        $request->validate(['from_installment' => ['required', 'integer', 'min:1']]);

        $updated = $this->entries->updateSeriesFrom(
            $seriesId,
            $request->integer('from_installment'),
            $request->user(),
            $request->except('from_installment'),
        );

        return FinancialEntryResource::collection($updated);
    }

    private function findForUser(User $user, int $id): FinancialEntry
    {
        $entry = $this->scope->scopeQuery(FinancialEntry::query(), $user)->with(self::RELATIONS)->findOrFail($id);
        Gate::forUser($user)->authorize('manage', $entry);

        return $entry;
    }

    /** @return Builder<FinancialEntry> */
    private function filteredQuery(Request $request): Builder
    {
        return $this->scope->scopeQuery(FinancialEntry::query(), $request->user())
            ->when($request->filled('nature'), fn (Builder $q) => $q->where('nature', $request->string('nature')->toString()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('financial_category_id'), fn (Builder $q) => $q->where('financial_category_id', $request->integer('financial_category_id')))
            ->when($request->filled('supplier_id'), fn (Builder $q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('due_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('due_date', '<=', $request->date('to')));
    }
}
