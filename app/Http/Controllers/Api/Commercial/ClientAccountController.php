<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\ClientAccountEntryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreClientAccountEntryRequest;
use App\Http\Requests\Commercial\UpdateClientAccountSettingsRequest;
use App\Http\Resources\Commercial\ClientAccountEntryResource;
use App\Models\CompanyClientAccount;
use App\Models\User;
use App\Services\Commercial\ClientAccountService;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Conta corrente do cliente — contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * Ver saldo/extrato é operação de balcão (qualquer membro ativo); ajuste manual e
 * `credit_limit`/`allow_credit_sale` são só de quem possui o escopo
 * (`CompanyClientAccountPolicy`).
 */
class ClientAccountController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly ProfessionalClientsQuery $clients,
        private readonly ClientAccountService $accounts,
    ) {}

    public function statement(Request $request, int $clientId): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', CompanyClientAccount::class);
        $client = $this->findClient($request->user(), $clientId);
        $ownership = $this->scope->ownershipFor($request->user());

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $request->filled('from') ? CarbonImmutable::parse($request->date('from')) : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->date('to')) : null;

        $summary = $this->accounts->accountSummaryFor($client, $ownership);

        return response()->json([
            'balance' => $summary['balance'],
            'credit_limit' => $summary['credit_limit'],
            'allow_credit_sale' => $summary['allow_credit_sale'],
            'data' => ClientAccountEntryResource::collection(
                $this->accounts->statementFor($client, $ownership, $from, $to)->load('recordedBy')
            ),
        ]);
    }

    public function storeEntry(StoreClientAccountEntryRequest $request, int $clientId): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', CompanyClientAccount::class);
        $client = $this->findClient($request->user(), $clientId);
        $ownership = $this->scope->ownershipFor($request->user());

        $entry = $this->accounts->recordManualEntry(
            $client,
            $ownership,
            ClientAccountEntryType::from($request->string('type')->toString()),
            (float) $request->float('amount'),
            $request->user(),
            $request->string('notes')->toString() ?: null,
        );

        return (new ClientAccountEntryResource($entry))
            ->additional(['message' => 'Lançamento registrado.'])
            ->response()
            ->setStatusCode(201);
    }

    public function updateSettings(UpdateClientAccountSettingsRequest $request, int $clientId): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', CompanyClientAccount::class);
        $client = $this->findClient($request->user(), $clientId);
        $ownership = $this->scope->ownershipFor($request->user());

        $account = $this->accounts->updateSettings(
            $client,
            $ownership,
            (float) $request->float('credit_limit'),
            $request->boolean('allow_credit_sale'),
        );

        return response()->json(['data' => [
            'client_id' => $client->id,
            'credit_limit' => (float) $account->credit_limit,
            'allow_credit_sale' => $account->allow_credit_sale,
            'current_balance' => (float) $account->current_balance,
        ], 'message' => 'Configuração de crédito atualizada.']);
    }

    private function findClient(User $user, int $clientId): User
    {
        $isClient = $this->clients->queryForAny($this->scope->teamUserIds($user))->whereKey($clientId)->exists();

        abort_unless($isClient, 404);

        return User::findOrFail($clientId);
    }
}
