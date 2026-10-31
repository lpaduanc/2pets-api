<?php

namespace App\Services\Crm;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\ClientRelationshipProfile;
use App\Models\CompanyClientAccount;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Collection;

/**
 * Retorno médio, nº de compras e saldo da conta corrente (crediário/fiado, doc 11) de cada perfil
 * da lista de clientes (PLANO 1.6) — duas queries para a página inteira, nunca uma por cliente.
 * O resultado vai em `ClientRelationshipProfile::$commercialMetrics`, lido pelo resource.
 *
 * Compras = vendas `paid` de `kind = sale` no escopo comercial de quem pergunta, todas as datas.
 * Saldo = `company_client_accounts.current_balance` do mesmo dono do perfil (organização; vet
 * volante: o profissional) — negativo = o cliente deve; sem conta, "0.00".
 */
final class ClientCommercialMetrics
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PurchaseRhythmCalculator $rhythm,
    ) {}

    /** @param  iterable<ClientRelationshipProfile>  $profiles */
    public function attachTo(User $user, iterable $profiles): void
    {
        $profiles = collect($profiles);

        if ($profiles->isEmpty()) {
            return;
        }

        $purchases = $this->purchasesByClient($user, $profiles->pluck('client_id')->unique()->values()->all());
        $balances = $this->balancesFor($profiles);

        $profiles->each(function (ClientRelationshipProfile $profile) use ($purchases, $balances): void {
            $span = $purchases->get($profile->client_id);
            $profile->commercialMetrics = [
                'purchase_count' => (int) ($span->purchases ?? 0),
                'average_return_days' => $this->rhythm->fromSpan((int) ($span->purchases ?? 0), (int) ($span->days ?? 0)),
                'client_account_balance' => (string) ($balances->get(self::ownerKey($profile->organization_id, $profile->professional_id, $profile->client_id)) ?? '0.00'),
            ];
        });
    }

    /**
     * @param  list<int>  $clientIds
     * @return Collection<int, object{purchases: int, days: int}>
     */
    private function purchasesByClient(User $user, array $clientIds): Collection
    {
        // Dia no fuso de Brasília: `sold_at` é gravado em UTC (timestamp sem fuso).
        $localDay = "((COALESCE(sales.sold_at, sales.created_at) AT TIME ZONE 'UTC') AT TIME ZONE '".PurchaseRhythmCalculator::TIMEZONE."')::date";

        return $this->scope->scopeQuery(Sale::query(), $user)
            ->whereIn('sales.client_id', $clientIds)
            ->where('sales.kind', SaleKind::SALE->value)
            ->where('sales.status', SaleStatus::PAID->value)
            ->groupBy('sales.client_id')
            ->selectRaw("sales.client_id, COUNT(*) AS purchases, (MAX({$localDay}) - MIN({$localDay})) AS days")
            ->toBase()
            ->get()
            ->keyBy('client_id');
    }

    /**
     * @param  Collection<int, ClientRelationshipProfile>  $profiles
     * @return Collection<string, string>
     */
    private function balancesFor(Collection $profiles): Collection
    {
        return CompanyClientAccount::query()
            ->whereIn('client_id', $profiles->pluck('client_id')->unique())
            ->where(fn ($owners) => $owners
                ->whereIn('organization_id', $profiles->pluck('organization_id')->filter()->unique())
                ->orWhere(fn ($own) => $own->whereNull('organization_id')->whereIn('professional_id', $profiles->pluck('professional_id')->filter()->unique())))
            ->get(['organization_id', 'professional_id', 'client_id', 'current_balance'])
            ->mapWithKeys(fn (CompanyClientAccount $account): array => [
                self::ownerKey($account->organization_id, $account->professional_id, $account->client_id) => (string) $account->current_balance,
            ]);
    }

    /** Conta de organização ignora `professional_id` (é `null` lá, ver `ClientAccountService::accountFor`). */
    private static function ownerKey(?int $organizationId, ?int $professionalId, int $clientId): string
    {
        return $organizationId !== null ? "org:{$organizationId}:{$clientId}" : "pro:{$professionalId}:{$clientId}";
    }
}
