<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\AcquirerSettlementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreAcquirerSettlementRequest;
use App\Http\Resources\Commercial\AcquirerSettlementResource;
use App\Models\AcquirerSettlement;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Commercial\AcquirerReconciliationService;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Conciliação de depósitos da adquirente — contrato
 * docs/gap-simplesvet/04-contas-bancarias-conciliacao-cartoes.md.
 *
 * Regra em `AcquirerReconciliationService`; autorização em `AcquirerSettlementPolicy`; escopo
 * em `CommercialScopeResolver`.
 */
class AcquirerSettlementController extends Controller
{
    public function __construct(
        private readonly AcquirerReconciliationService $reconciliation,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(AcquirerSettlementStatus::class)],
            'from' => ['required_with:to', 'date'],
            'to' => ['required_with:from', 'date', 'after_or_equal:from'],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        $settlements = $this->scopedQuery($request->user())
            ->with(AcquirerSettlementResource::RESOURCE_RELATIONS)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('payment_method_id'), fn (Builder $q) => $q->where('payment_method_id', $request->integer('payment_method_id')))
            ->when($request->filled('from') && $request->filled('to'), fn (Builder $q) => $q->whereBetween(
                'deposit_date',
                [$request->date('from')->toDateString(), $request->date('to')->toDateString()]
            ))
            ->orderByDesc('deposit_date')
            ->get();

        $counters = $request->filled('from') && $request->filled('to')
            ? $this->reconciliation->counters($request->user(), $request->date('from'), $request->date('to'))
            : null;

        return response()->json([
            'data' => AcquirerSettlementResource::collection($settlements),
            'counters' => $counters,
        ]);
    }

    public function show(Request $request, int $id): AcquirerSettlementResource
    {
        $settlement = $this->findForUser($request->user(), $id, 'view');

        return new AcquirerSettlementResource(
            $settlement->load([...AcquirerSettlementResource::RESOURCE_RELATIONS, 'items.receipt'])
        );
    }

    public function store(StoreAcquirerSettlementRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', AcquirerSettlement::class);

        $data = $request->validated();
        $this->assertInScope($request->user(), PaymentMethod::class, 'payment_method_id', $data['payment_method_id']);
        $this->assertInScope($request->user(), FinancialAccount::class, 'destination_account_id', $data['destination_account_id'] ?? null);

        $settlement = AcquirerSettlement::create($data + [
            'fee_amount' => $data['fee_amount'] ?? 0,
            'status' => AcquirerSettlementStatus::PENDING,
        ] + $this->scope->ownershipFor($request->user()));

        return (new AcquirerSettlementResource($settlement->load(AcquirerSettlementResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Depósito cadastrado.'])
            ->response()
            ->setStatusCode(201);
    }

    public function expected(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->reconciliation->expectedSettlements($request->user(), $request->date('from'), $request->date('to')),
        ]);
    }

    public function reconcile(Request $request, int $id): AcquirerSettlementResource
    {
        $settlement = $this->findForUser($request->user(), $id, 'reconcile');

        $request->validate([
            'receipt_ids' => ['required', 'array', 'min:1'],
            'receipt_ids.*' => ['integer'],
        ]);

        $reconciled = $this->reconciliation->match($settlement, $request->input('receipt_ids'), $request->user());

        return new AcquirerSettlementResource($reconciled->load('items.receipt'));
    }

    public function markDivergent(Request $request, int $id): AcquirerSettlementResource
    {
        $settlement = $this->findForUser($request->user(), $id, 'reconcile');

        $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return new AcquirerSettlementResource(
            $this->reconciliation->markDivergent($settlement, $request->user(), $request->string('note')->toString())
        );
    }

    private function findForUser(User $user, int $id, string $ability): AcquirerSettlement
    {
        $settlement = $this->scopedQuery($user)->findOrFail($id);

        Gate::forUser($user)->authorize($ability, $settlement);

        return $settlement;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function assertInScope(User $user, string $model, string $field, mixed $id): void
    {
        if ($id === null) {
            return;
        }

        if (! $this->scope->scopeQuery($model::query(), $user)->whereKey((int) $id)->exists()) {
            throw ValidationException::withMessages([$field => 'Registro não encontrado nesta clínica.']);
        }
    }

    /**
     * @return Builder<AcquirerSettlement>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(AcquirerSettlement::query(), $user);
    }
}
