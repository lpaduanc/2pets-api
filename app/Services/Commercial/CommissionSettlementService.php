<?php

namespace App\Services\Commercial;

use App\Enums\CommissionSettlementStatus;
use App\Enums\CommissionTrigger;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Enums\SoldPackageSessionStatus;
use App\Enums\SoldPackageStatus;
use App\Events\Sales\CommissionRecalculated;
use App\Models\CommissionRule;
use App\Models\CommissionSettlement;
use App\Models\OrganizationMember;
use App\Models\SaleItem;
use App\Models\ServicePackage;
use App\Models\SoldPackageSession;
use App\Models\User;
use App\Services\Commercial\Package\PackageSessionEconomics;
use App\Services\Commercial\Reversal\CommissionDeductionApplier;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prévia e fechamento de comissão por funcionário — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 *
 * Regra de negócio 1 ("comissão só entra em fechamento sobre venda efetivamente recebida,
 * nunca sobre venda emitida") vale para toda regra. O gatilho da regra (PLANO 2.6,
 * `commission_rules.trigger`) escolhe a data do fato comparada com a data de corte
 * (`received_until`) — ver `CommissionEligibility`.
 */
final class CommissionSettlementService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CommissionRuleResolver $resolver,
        private readonly CommissionCalculator $calculator,
        private readonly CommissionDeductionApplier $deductions,
        private readonly CommissionEligibility $eligibility,
        private readonly PackageSessionEconomics $sessions,
    ) {}

    /**
     * O que fecharia se `close()` fosse chamado agora — mesma query, sem gravar nada.
     *
     * Linhas de item de venda e de sessão de pacote consumida (PLANO 2.7).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function openSummary(User $user, int $staffId, CarbonImmutable $receivedUntil): Collection
    {
        $this->assertStaffVisible($user, $staffId);

        return $this->eligibleLines($staffId, $receivedUntil, lock: false);
    }

    /**
     * Fecha o período: cria o `commission_settlement` e uma linha por `sale_item` elegível.
     * Cada item entra em EXATAMENTE um fechamento (`unique(sale_item_id)` no banco garante,
     * mesmo sob concorrência).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function close(User $user, array $attributes): CommissionSettlement
    {
        $staffId = (int) $attributes['staff_id'];
        $receivedUntil = CarbonImmutable::parse($attributes['received_until']);

        $this->assertStaffVisible($user, $staffId);

        return DB::transaction(function () use ($user, $attributes, $staffId, $receivedUntil): CommissionSettlement {
            $priced = $this->eligibleLines($staffId, $receivedUntil, lock: true);

            abort_if($priced->isEmpty(), 422, 'Nenhum item elegível para fechar neste período.');

            $settlement = CommissionSettlement::create([
                'staff_id' => $staffId,
                'period_from' => $attributes['period_from'],
                'period_to' => $attributes['period_to'],
                'received_until' => $receivedUntil->toDateString(),
                'status' => CommissionSettlementStatus::CLOSED,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ] + $this->scope->ownershipFor($user));

            $total = $priced->sum(function (array $line) use ($settlement): float {
                $settlement->items()->create([
                    'sale_item_id' => $line['sale_item']?->id,
                    'sold_package_session_id' => $line['session']?->id,
                    'commission_rule_id' => $line['rule']?->id,
                    'base_amount' => $line['base_amount'],
                    'commission_amount' => $line['commission_amount'],
                ]);

                return $line['commission_amount'];
            });

            // Comissão estornada que o profissional ainda deve (PLANO 2.3) sai deste fechamento,
            // sem nunca deixá-lo negativo; o que não couber fica para o próximo.
            $gross = Money::of($total);
            $settlement->update(['total_amount' => $gross->minus($this->deductions->applyTo($settlement, $gross))->toString()]);

            // A comissão fechada passa a ser a da rentabilidade (PLANO 1.5).
            $priced->pluck('sale_id')->unique()->each(fn (int $saleId) => CommissionRecalculated::dispatch($saleId));

            return $settlement->fresh(['items.saleItem', 'items.soldPackageSession']);
        });
    }

    /**
     * Marca a liquidação — não recalcula nada (regra de negócio 4, fechamento é imutável).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function pay(CommissionSettlement $settlement, array $attributes): CommissionSettlement
    {
        abort_if($settlement->status === CommissionSettlementStatus::OPEN, 422, 'Feche o período antes de marcar como pago.');
        abort_if($settlement->status === CommissionSettlementStatus::PAID, 422, 'Este fechamento já está pago.');

        $settlement->update([
            'status' => CommissionSettlementStatus::PAID,
            'paid_at' => now(),
            'payment_method' => $attributes['payment_method'] ?? null,
            'payment_reference' => $attributes['payment_reference'] ?? null,
        ]);

        return $settlement;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function eligibleLines(int $staffId, CarbonImmutable $cutoff, bool $lock): Collection
    {
        $items = $this->candidateItemsQuery($staffId)->when($lock, fn (Builder $q) => $q->lockForUpdate())->get();
        $sessions = $this->candidateSessionsQuery($staffId)->when($lock, fn (Builder $q) => $q->lockForUpdate())->get();

        return $this->eligible($items, $cutoff)->concat($this->eligibleSessions($sessions, $cutoff))->values();
    }

    /**
     * Sessão de pacote consumida pelo profissional (PLANO 2.7), com a regra resolvida como item
     * do serviço e a base no valor da sessão — ver `PackageSessionEconomics`.
     *
     * @param  Collection<int, SoldPackageSession>  $sessions
     * @return Collection<int, array<string, mixed>>
     */
    private function eligibleSessions(Collection $sessions, CarbonImmutable $cutoff): Collection
    {
        $shares = [];

        return $sessions->map(function (SoldPackageSession $session) use (&$shares): array {
            $package = $session->soldPackage;
            $shares[$package->id] ??= $this->sessions->lineShares($package);
            $rule = $this->sessions->rule($session);
            $trigger = $this->sessions->effectiveTrigger($session, $rule);

            return ['sale_item' => null, 'session' => $session, 'sale_id' => $package->sale_id, 'description' => $session->description(),
                'rule' => $rule, 'trigger' => $trigger, 'born_at' => $this->sessions->bornAt($session, $trigger)]
                + $this->sessions->commission($session, $rule, $shares[$package->id][$session->id]['card_fee'] ?? Money::zero());
        })->filter(fn (array $line): bool => $line['born_at'] !== null && $line['born_at']->lte($cutoff->endOfDay()))->values();
    }

    /** @return Builder<SoldPackageSession> */
    private function candidateSessionsQuery(int $staffId): Builder
    {
        return SoldPackageSession::query()
            ->with(['soldPackageItem.service', 'consumption.appointment', 'soldPackage.servicePackage', 'soldPackage.saleItem',
                'soldPackage.sessions', 'soldPackage.sale.items', 'soldPackage.sale.receipts', 'soldPackage.sale.organization'])
            ->orderBy('id')
            ->where('staff_id', $staffId)
            ->where('status', SoldPackageSessionStatus::CONSUMED->value)
            ->whereDoesntHave('commissionSettlementItem')
            ->whereHas('soldPackage', fn (Builder $package) => $package->where('status', '!=', SoldPackageStatus::CANCELLED->value)
                ->whereHas('sale', fn (Builder $sale) => $sale->where('status', SaleStatus::PAID->value)->where('kind', SaleKind::SALE->value)
                    ->whereHas('receipts', fn (Builder $receipts) => $receipts->whereNull('reversed_at'))));
    }

    /**
     * Precifica cada candidato com a regra resolvida UMA vez e fica só com o que nasceu até a
     * data de corte, pelo gatilho da regra. `received_until` chega como DATA e as datas dos
     * fatos são timestamps: `CommissionEligibility` compara com o FIM do dia informado (achado
     * antigo: "fechar comissão de hoje" não pode perder o recebimento de hoje).
     *
     * @param  Collection<int, SaleItem>  $items
     * @return Collection<int, array{sale_item: SaleItem, rule: ?CommissionRule, trigger: CommissionTrigger, born_at: CarbonImmutable, base_amount: float, commission_amount: float}>
     */
    private function eligible(Collection $items, CarbonImmutable $cutoff): Collection
    {
        return $items->map(function (SaleItem $item): array {
            $rule = $this->resolver->resolve($item);
            $trigger = $this->eligibility->effectiveTrigger($item, $rule);

            return ['sale_item' => $item, 'session' => null, 'sale_id' => $item->sale_id, 'description' => $item->description,
                'rule' => $rule, 'trigger' => $trigger, 'born_at' => $this->eligibility->bornAt($item, $trigger)]
                + $this->calculator->calculate($item, $rule);
        })->filter(fn (array $line): bool => $line['born_at'] !== null && $line['born_at']->lte($cutoff->endOfDay()))->values();
    }

    /**
     * Item do profissional, de venda paga com recebimento valendo e ainda não consumido por
     * outro fechamento — o piso comum da regra de negócio 1 e o critério de unicidade. A data
     * (por gatilho) é filtrada em `eligible()`, porque depende da regra resolvida por item.
     *
     * @return Builder<SaleItem>
     */
    private function candidateItemsQuery(int $staffId): Builder
    {
        return SaleItem::query()
            ->with(['sellable', 'appointment', 'sale.items', 'sale.receipts'])
            ->orderBy('id')
            ->where('staff_id', $staffId)
            // A linha do pacote não comissiona: a comissão vai sessão a sessão, pelo valor de
            // cada uma, para quem executou (PLANO 2.7) — senão o mesmo dinheiro pagaria duas vezes.
            ->where('sellable_type', '!=', ServicePackage::class)
            ->whereDoesntHave('commissionSettlementItem')
            ->whereHas('sale', function (Builder $query): void {
                $query->where('status', SaleStatus::PAID->value)
                    ->where('kind', SaleKind::SALE->value)
                    ->whereHas('receipts', fn (Builder $receipts) => $receipts->whereNull('reversed_at'));
            });
    }

    private function assertStaffVisible(User $user, int $staffId): void
    {
        $member = OrganizationMember::findOrFail($staffId);

        abort_unless($user->isActiveMemberOfOrganization($member->organization_id), 422, 'Funcionário não encontrado nesta clínica.');
    }
}
