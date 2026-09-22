<?php

namespace App\Services\Commercial;

use App\Enums\CommissionSettlementStatus;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\CommissionRule;
use App\Models\CommissionSettlement;
use App\Models\OrganizationMember;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prévia e fechamento de comissão por funcionário — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 *
 * Regra de negócio 1 ("comissão só entra em fechamento sobre venda efetivamente recebida,
 * nunca sobre venda emitida") é aplicada de forma FIXA aqui, para toda regra — o campo
 * `commission_rules.only_when_received` existe no schema, mas esta rodada não implementa o
 * caminho `false` (ver o comentário da migration).
 */
final class CommissionSettlementService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CommissionRuleResolver $resolver,
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * O que fecharia se `close()` fosse chamado agora — mesma query, sem gravar nada.
     *
     * @return Collection<int, array{sale_item: SaleItem, rule: ?CommissionRule, base_amount: float, commission_amount: float}>
     */
    public function openSummary(User $user, int $staffId, CarbonImmutable $receivedUntil): Collection
    {
        $this->assertStaffVisible($user, $staffId);

        return $this->eligibleItemsQuery($staffId, $receivedUntil)->get()->map(fn (SaleItem $item) => $this->priceItem($item));
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
            $items = $this->eligibleItemsQuery($staffId, $receivedUntil)->lockForUpdate()->get();

            abort_if($items->isEmpty(), 422, 'Nenhum item elegível para fechar neste período.');

            $settlement = CommissionSettlement::create([
                'staff_id' => $staffId,
                'period_from' => $attributes['period_from'],
                'period_to' => $attributes['period_to'],
                'received_until' => $receivedUntil->toDateString(),
                'status' => CommissionSettlementStatus::CLOSED,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ] + $this->scope->ownershipFor($user));

            $total = $items->sum(function (SaleItem $item) use ($settlement): float {
                $priced = $this->priceItem($item);

                $settlement->items()->create([
                    'sale_item_id' => $item->id,
                    'commission_rule_id' => $priced['rule']?->id,
                    'base_amount' => $priced['base_amount'],
                    'commission_amount' => $priced['commission_amount'],
                ]);

                return $priced['commission_amount'];
            });

            $settlement->update(['total_amount' => round($total, 2)]);

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
     * @return array{sale_item: SaleItem, rule: ?CommissionRule, base_amount: float, commission_amount: float}
     */
    private function priceItem(SaleItem $item): array
    {
        $rule = $this->resolver->resolve($item);

        return ['sale_item' => $item, 'rule' => $rule] + $this->calculator->calculate($item, $rule);
    }

    /**
     * Item pago, recebido até a data do fechamento e ainda não consumido por outro
     * fechamento — as três condições da regra de negócio 1 e do critério de unicidade.
     *
     * @return Builder<SaleItem>
     */
    private function eligibleItemsQuery(int $staffId, CarbonImmutable $receivedUntil): Builder
    {
        // `received_until` chega como DATA (`YYYY-MM-DD`, meia-noite após o `parse()`), mas
        // `sale_receipts.received_at` é timestamp completo (`SaleService::registerReceipt()`
        // usa `now()`). Comparar a meia-noite direto excluiria todo recebimento do PRÓPRIO dia
        // do fechamento — "fechar comissão de hoje" nunca teria item elegível. Corrigido para
        // o fim do dia informado (achado desta auditoria, ver `CommissionOnlyWhenReceivedTest`).
        $receivedUntilEndOfDay = $receivedUntil->endOfDay();

        return SaleItem::query()
            ->with(['sellable', 'sale.items', 'sale.receipts'])
            ->orderBy('id')
            ->where('staff_id', $staffId)
            ->whereDoesntHave('commissionSettlementItem')
            ->whereHas('sale', function (Builder $query) use ($receivedUntilEndOfDay): void {
                $query->where('status', SaleStatus::PAID->value)
                    ->where('kind', SaleKind::SALE->value)
                    ->whereRaw(
                        '(select max(received_at) from sale_receipts where sale_receipts.sale_id = sales.id) <= ?',
                        [$receivedUntilEndOfDay]
                    );
            });
    }

    private function assertStaffVisible(User $user, int $staffId): void
    {
        $member = OrganizationMember::findOrFail($staffId);

        abort_unless($user->isActiveMemberOfOrganization($member->organization_id), 422, 'Funcionário não encontrado nesta clínica.');
    }
}
