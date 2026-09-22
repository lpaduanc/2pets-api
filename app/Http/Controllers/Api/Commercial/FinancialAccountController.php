<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\FinancialAccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreFinancialAccountRequest;
use App\Http\Resources\Commercial\FinancialAccountResource;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Commercial\AccountBalanceService;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Contas bancárias, gaveta de caixa e operadora de cartão — contrato
 * docs/gap-simplesvet/04-contas-bancarias-conciliacao-cartoes.md.
 *
 * Sem `destroy`: conta usada em recebimento é histórico. Desativa-se (`active = false`), mesma
 * regra do `PaymentMethodController`.
 */
class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly AccountBalanceService $balances,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'active' => ['nullable', 'boolean'],
            'type' => ['nullable', Rule::enum(FinancialAccountType::class)],
        ]);

        $accounts = $this->scopedQuery($request->user())
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->has('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->orderBy('name')
            ->get();

        $balances = $this->balances->balancesFor($accounts);

        $data = $accounts
            ->map(fn (FinancialAccount $account): FinancialAccountResource => new FinancialAccountResource(
                $account,
                $balances[$account->id]
            ))
            ->values();

        return response()->json(['data' => $data]);
    }

    public function store(StoreFinancialAccountRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FinancialAccount::class);

        $account = FinancialAccount::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return (new FinancialAccountResource($account, 0.0))
            ->additional(['message' => 'Conta cadastrada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): FinancialAccountResource
    {
        $account = $this->findForUser($request->user(), $id, 'view');

        return new FinancialAccountResource($account, $this->balances->balance($account));
    }

    public function update(StoreFinancialAccountRequest $request, int $id): FinancialAccountResource
    {
        $account = $this->findForUser($request->user(), $id, 'manage');

        $account->update($request->validated());

        return new FinancialAccountResource($account->fresh(), $this->balances->balance($account));
    }

    public function balance(Request $request, int $id): JsonResponse
    {
        $account = $this->findForUser($request->user(), $id, 'view');
        $request->validate(['at' => ['nullable', 'date']]);

        $at = $request->filled('at') ? $request->date('at') : null;

        return response()->json(['data' => [
            'account_id' => $account->id,
            'balance' => $this->balances->balance($account, $at),
            'at' => $at?->toDateString() ?? now()->toDateString(),
        ]]);
    }

    public function statement(Request $request, int $id): JsonResponse
    {
        $account = $this->findForUser($request->user(), $id, 'view');
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->balances->statement($account, $request->date('from'), $request->date('to')),
        ]);
    }

    private function findForUser(User $user, int $id, string $ability): FinancialAccount
    {
        $account = $this->scopedQuery($user)->findOrFail($id);

        Gate::forUser($user)->authorize($ability, $account);

        return $account;
    }

    /**
     * @return Builder<FinancialAccount>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(FinancialAccount::query(), $user);
    }
}
