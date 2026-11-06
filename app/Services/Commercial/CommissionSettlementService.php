<?php

namespace App\Services\Commercial;

use App\Enums\CommissionSettlementStatus;
use App\Enums\CommissionTrigger;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Events\Sales\CommissionRecalculated;
use App\Models\CommissionRule;
use App\Models\CommissionSettlement;
use App\Models\OrganizationMember;
use App\Models\SaleItem;
use App\Models\User;
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
    ) {}

    /**
     * O que fecharia se `close()` fosse chamado agora — mesma query, sem gravar nada.
     *
     * @return Collection<int, array{sale_item: SaleItem, rule: ?CommissionRule, trigger: CommissionTrigger, born_at: CarbonImmutable, base_amount: float, commission_amount: float}>
     */
    public function openSummary(User $user, int $staffId, CarbonImmutable $receivedUntil): Collection
    {
        $this->assertStaffVisible($user, $staffId);

        return $this->eligible($this->candidateItemsQuery($staffId)->get(), $receivedUntil);
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
            $priced = $this->eligible($this->candidateItemsQuery($staffId)->lockForUpdate()->get(), $receivedUntil);

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
                    'sale_item_id' => $line['sale_item']->id,
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
            $priced->map(fn (array $line): int => $line['sale_item']->sale_id)->unique()->each(fn (int $saleId) => CommissionRecalculated::dispatch($saleId));

            return $settlement->fresh('items.saleItem');
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

            return ['sale_item' => $item, 'rule' => $rule, 'trigger' => $trigger, 'born_at' => $this->eligibility->bornAt($item, $trigger)]
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
