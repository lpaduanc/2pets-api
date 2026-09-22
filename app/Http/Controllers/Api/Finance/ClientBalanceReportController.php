<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\CompanyClientAccount;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Relatório consolidado de saldos de cliente — contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md. Só `OWNER`
 * (`CompanyClientAccountPolicy::viewAny`): é visão financeira agregada, mesma régua do doc 02.
 */
class ClientBalanceReportController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', CompanyClientAccount::class);

        $request->validate(['situation' => ['nullable', Rule::in(['debtor', 'creditor', 'all'])]]);
        $situation = $request->string('situation', 'all')->toString();

        $accounts = $this->scopedQuery($request->user())
            ->when($situation === 'debtor', fn (Builder $q) => $q->where('current_balance', '<', 0))
            ->when($situation === 'creditor', fn (Builder $q) => $q->where('current_balance', '>', 0))
            ->with('client:id,name')
            ->orderBy('current_balance')
            ->get();

        return response()->json(['data' => $accounts->map(fn (CompanyClientAccount $account): array => [
            'client' => ['id' => $account->client->id, 'name' => $account->client->name],
            'balance' => (float) $account->current_balance,
            'situation' => $account->isDebtor() ? 'debtor' : ($account->current_balance > 0 ? 'creditor' : 'settled'),
            'credit_limit' => (float) $account->credit_limit,
            'allow_credit_sale' => $account->allow_credit_sale,
            'last_purchase_at' => $account->last_purchase_at?->toIso8601String(),
        ])->values()]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', CompanyClientAccount::class);

        $receivable = (float) (clone $this->scopedQuery($request->user()))->where('current_balance', '<', 0)->sum('current_balance');

        return response()->json(['data' => [
            'receivable_from_clients' => round(abs($receivable), 2),
            'debtor_count' => (clone $this->scopedQuery($request->user()))->where('current_balance', '<', 0)->count(),
        ]]);
    }

    /**
     * @return Builder<CompanyClientAccount>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(CompanyClientAccount::query(), $user);
    }
}
