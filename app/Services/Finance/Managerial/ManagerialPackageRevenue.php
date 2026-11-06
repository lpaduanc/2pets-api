<?php

namespace App\Services\Finance\Managerial;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Enums\SoldPackageSessionStatus;
use App\Models\Sale;
use App\Models\SoldPackage;
use App\Models\SoldPackageSession;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Package\PackageSessionEconomics;
use App\Services\Finance\CostCenter\CostCenterResolver;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Receita de pacote por competência na DRE gerencial (PLANO 2.7). A linha do pacote sai das
 * vendas (`ManagerialSalesTotals`) e cada sessão é reconhecida UMA vez, no mês em que se resolve:
 *  - usada: no mês da baixa — receita = valor da sessão, custo do serviço na baixa, parte da taxa
 *    e do imposto da linha, comissão da sessão;
 *  - vencida como receita de expiração: no dia seguinte ao vencimento — receita, imposto e taxa;
 *  - vencida como crédito ao cliente: no dia da resolução — só a parte da taxa (custo que a
 *    clínica pagou). Não é receita: ela nasce quando o crédito for usado numa venda;
 *  - a usar: receita diferida (passivo), fora do resultado — ver `deferral()`.
 * Só pacote de venda paga e não cancelada, como o resto da DRE gerencial. O dinheiro continua
 * onde já estava: caixa e financeiro não mudam (o que já é caixa continua caixa).
 */
final class ManagerialPackageRevenue
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PackageSessionEconomics $economics,
        private readonly CostCenterResolver $costCenters,
    ) {}

    /**
     * Por centro de custo do SERVIÇO da sessão (PLANO 2.4) e mês, nas chaves de
     * `ManagerialSalesTotals`.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function byCenterAndMonth(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->byDimensionAndMonth($user, $from, $to, ManagerialSalesDimension::COST_CENTER);
    }

    /**
     * Pela dimensão pedida: centro do serviço consumido, tipo (sempre `services`) ou quem
     * executou a sessão (`staff_id`).
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function byDimensionAndMonth(User $user, CarbonImmutable $from, CarbonImmutable $to, ManagerialSalesDimension $dimension): array
    {
        $byCenter = [];
        $packages = $this->packagesTouching($user, $from, $to);
        $centers = $this->costCenters->forServices($packages->flatMap(fn (SoldPackage $p) => $p->sessions->map(fn (SoldPackageSession $s): int => $s->soldPackageItem->service_id))->all());
        $keyOf = fn (SoldPackageSession $session): ?string => match ($dimension) {
            ManagerialSalesDimension::COST_CENTER => isset($centers[$session->soldPackageItem->service_id]) ? (string) $centers[$session->soldPackageItem->service_id] : null,
            ManagerialSalesDimension::ITEM_TYPE => ManagerialSalesDimension::SERVICES,
            ManagerialSalesDimension::STAFF => $session->staff_id === null ? null : (string) $session->staff_id,
        };

        foreach ($packages as $package) {
            $shares = $this->economics->lineShares($package);

            foreach ($package->sessions as $session) {
                $date = $this->recognizedOn($session, $package);

                if ($date === null || $date->lt($from->startOfDay()) || $date->gt($to->endOfDay())) {
                    continue;
                }

                $share = $shares[$session->id] ?? ['card_fee' => Money::zero(), 'tax' => Money::zero()];
                $this->add($byCenter[$keyOf($session) ?? ManagerialCostCenters::UNALLOCATED][$date->format('Y-m')], $this->valuesOf($session, $share));
            }
        }

        return array_map(fn (array $months): array => array_map(fn (array $values): array => array_map(fn (Money $value): string => $value->toString(), $values), $months), $byCenter);
    }

    /**
     * Movimento da receita diferida no período: saldo final = sessões vendidas até `to` ainda não
     * resolvidas em `to`; inicial = final − vendido + reconhecido + vencido como receita +
     * creditado ao cliente.
     *
     * @return array{opening: string, sold: string, recognized: string, expired_revenue: string, credited: string, closing: string}
     */
    public function deferral(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sold = $recognized = $expired = $credited = $closing = Money::zero();

        foreach ($this->paidPackages($user)->whereDate('sold_packages.sold_at', '<=', $to->toDateString())->with('sessions')->get() as $package) {
            foreach ($package->sessions as $session) {
                $amount = Money::of($session->amount);
                $date = $this->recognizedOn($session, $package);
                $soldInPeriod = $package->sold_at->between($from->startOfDay(), $to->endOfDay());
                $sold = $soldInPeriod ? $sold->plus($amount) : $sold;
                $closing = $date === null || $date->gt($to->endOfDay()) ? $closing->plus($amount) : $closing;

                if ($date === null || $date->lt($from->startOfDay()) || $date->gt($to->endOfDay())) {
                    continue;
                }

                match ($session->status) {
                    SoldPackageSessionStatus::CONSUMED => $recognized = $recognized->plus($amount),
                    SoldPackageSessionStatus::EXPIRED => $expired = $expired->plus($amount),
                    default => $credited = $credited->plus($amount),
                };
            }
        }

        return [
            'opening' => $closing->minus($sold)->plus($recognized)->plus($expired)->plus($credited)->toString(),
            'sold' => $sold->toString(),
            'recognized' => $recognized->toString(),
            'expired_revenue' => $expired->toString(),
            'credited' => $credited->toString(),
            'closing' => $closing->toString(),
        ];
    }

    /** Dia em que a sessão sai da receita diferida; `null` se ainda é diferida. */
    private function recognizedOn(SoldPackageSession $session, SoldPackage $package): ?CarbonImmutable
    {
        return match ($session->status) {
            SoldPackageSessionStatus::CONSUMED => $session->consumed_at === null ? null : CarbonImmutable::instance($session->consumed_at),
            SoldPackageSessionStatus::EXPIRED => $package->expires_at === null ? null : CarbonImmutable::instance($package->expires_at)->addDay(),
            SoldPackageSessionStatus::CREDITED => $session->resolved_at === null ? null : CarbonImmutable::instance($session->resolved_at),
            default => null,
        };
    }

    /**
     * @param  array{card_fee: Money, tax: Money}  $share
     * @return array<string, Money>
     */
    private function valuesOf(SoldPackageSession $session, array $share): array
    {
        $amount = Money::of($session->amount);

        return match ($session->status) {
            SoldPackageSessionStatus::CONSUMED => [
                'gross_revenue' => $amount, 'sales_revenue' => $amount, 'taxes' => $share['tax'], 'card_fees' => $share['card_fee'],
                'cost_services' => Money::of($session->unit_cost ?? '0'),
                'commissions' => $this->economics->commissionForProfitability($session, $share['card_fee']),
            ],
            SoldPackageSessionStatus::EXPIRED => ['gross_revenue' => $amount, 'sales_revenue' => $amount, 'taxes' => $share['tax'], 'card_fees' => $share['card_fee']],
            default => ['card_fees' => $share['card_fee']],
        };
    }

    /**
     * @param  array<string, Money>|null  $month
     * @param  array<string, Money>  $values
     */
    private function add(?array &$month, array $values): void
    {
        $month ??= [];

        foreach ($values as $key => $value) {
            $month[$key] = ($month[$key] ?? Money::zero())->plus($value);
        }
    }

    /** @return Collection<int, SoldPackage> */
    private function packagesTouching(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();

        return $this->paidPackages($user)
            ->whereHas('sessions', fn (Builder $q) => $q
                ->where(fn (Builder $c) => $c->where('status', SoldPackageSessionStatus::CONSUMED->value)->whereBetween('consumed_at', [$start, $end]))
                ->orWhere(fn (Builder $c) => $c->where('status', SoldPackageSessionStatus::CREDITED->value)->whereBetween('resolved_at', [$start, $end]))
                ->orWhere(fn (Builder $c) => $c->where('status', SoldPackageSessionStatus::EXPIRED->value)
                    ->whereHas('soldPackage', fn (Builder $p) => $p->whereBetween('expires_at', [$start->subDay()->toDateString(), $end->subDay()->toDateString()]))))
            ->with(['sessions.soldPackageItem.service', 'sessions.consumption.appointment', 'sessions.commissionSettlementItem',
                'saleItem', 'servicePackage', 'sale.items', 'sale.receipts', 'sale.organization'])
            ->get()
            ->each(fn (SoldPackage $package) => $package->sessions->each(fn (SoldPackageSession $s) => $s->setRelation('soldPackage', $package)));
    }

    /** @return Builder<SoldPackage> */
    private function paidPackages(User $user): Builder
    {
        $sales = $this->scope->scopeQuery(Sale::query(), $user)
            ->where('sales.kind', SaleKind::SALE->value)
            ->where('sales.status', SaleStatus::PAID->value)
            ->select('sales.id');

        return SoldPackage::query()->whereIn('sold_packages.sale_id', $sales)->where('sold_packages.status', '!=', 'cancelled');
    }
}
