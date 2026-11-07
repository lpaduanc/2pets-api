<?php

namespace App\Services\Commercial\Package;

use App\Enums\ExpiredPackagePolicy;
use App\Enums\SaleStatus;
use App\Enums\SoldPackageSessionStatus;
use App\Enums\SoldPackageStatus;
use App\Models\SoldPackage;
use App\Models\User;
use App\Services\Commercial\ClientAccountService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Saldo de pacote vencido (PLANO 2.7; decisão pendente 1 da seção 6). Pela política da
 * organização (`organizations.expired_package_policy`):
 *  - `client_credit` (padrão): o valor das sessões não usadas vira `PACKAGE_CREDIT` na conta
 *    corrente do cliente (`client_account_entries`); as sessões ficam `credited`. Não é receita:
 *    a receita nasce quando o crédito for usado numa venda;
 *  - `expiration_revenue`: as sessões ficam `expired` e o valor é receita de expiração na DRE
 *    gerencial, na competência do dia seguinte ao vencimento.
 *
 * Gatilho: o comando agendado `sold-packages:resolve-expired` (diário). Idempotente — o pacote
 * resolvido grava `expiration_resolved_at` e nunca é resolvido de novo; o crédito também é
 * idempotente por pacote. Só pacote de venda PAGA: crédito de dinheiro que não entrou não existe
 * (a venda que voltar a ser paga é resolvida na próxima rodada).
 */
final class SoldPackageExpirationService
{
    public function __construct(private readonly ClientAccountService $clientAccounts) {}

    /** @return Builder<SoldPackage> */
    public function pendingResolution(?CarbonImmutable $today = null): Builder
    {
        return SoldPackage::query()
            ->whereNull('expiration_resolved_at')
            ->where('status', '!=', SoldPackageStatus::CANCELLED->value)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', ($today ?? \App\Support\BusinessClock::today())->toDateString())
            ->whereHas('sessions', fn (Builder $q) => $q->where('status', SoldPackageSessionStatus::PENDING->value))
            ->whereHas('sale', fn (Builder $q) => $q->where('status', SaleStatus::PAID->value));
    }

    /** @return int quantos pacotes foram resolvidos */
    public function resolveAll(?User $actor = null): int
    {
        $count = 0;
        $this->pendingResolution()->with('organization')->orderBy('id')
            ->each(function (SoldPackage $package) use ($actor, &$count): void {
                $count += $this->resolve($package, $actor) ? 1 : 0;
            });

        return $count;
    }

    public function resolve(SoldPackage $package, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($package, $actor): bool {
            $locked = SoldPackage::query()->whereKey($package->id)->lockForUpdate()->first();

            if ($locked === null || $locked->expiration_resolved_at !== null || ! $locked->isPastValidity()) {
                return false;
            }

            $locked->loadMissing(['client', 'organization']);
            $pending = $locked->sessions()->where('status', SoldPackageSessionStatus::PENDING->value)->get();
            $amount = Money::sum($pending->pluck('amount'));
            $policy = $locked->organization?->expired_package_policy ?? ExpiredPackagePolicy::CLIENT_CREDIT;
            $resolvedAt = now();
            $entryId = null;

            if ($policy === ExpiredPackagePolicy::CLIENT_CREDIT && $amount->isPositive()) {
                $entryId = $this->clientAccounts->creditForExpiredPackage(
                    $locked->client, ['organization_id' => $locked->organization_id, 'professional_id' => $locked->professional_id],
                    $amount->toFloat(), $locked->id, $actor,
                )->id;
            }

            $locked->sessions()->whereKey($pending->modelKeys())->update([
                'status' => ($policy === ExpiredPackagePolicy::CLIENT_CREDIT ? SoldPackageSessionStatus::CREDITED : SoldPackageSessionStatus::EXPIRED)->value,
                'resolved_at' => $resolvedAt,
            ]);
            $locked->update([
                'expiration_resolution' => $policy,
                'expiration_resolved_at' => $resolvedAt,
                'expiration_amount' => $amount->toString(),
                'client_account_entry_id' => $entryId,
            ]);

            return true;
        });
    }
}
