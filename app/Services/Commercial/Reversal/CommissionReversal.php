<?php

namespace App\Services\Commercial\Reversal;

use App\Enums\CommissionTrigger;
use App\Models\Appointment;
use App\Models\CommissionClawback;
use App\Models\CommissionRule;
use App\Models\CommissionSettlementItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;

/**
 * Comissão de uma venda que deixou de estar paga por estorno de recebimento (PLANO 2.3). A
 * comissão só nasce de item de venda PAGA (gatilho de recebimento, doc 09):
 *  - item ainda não fechado: nada a gravar — sai sozinho do "elegível" e volta quando a venda
 *    for paga de novo;
 *  - item já fechado: marcado `reversed_at` com o motivo (some da rentabilidade; a venda paga de
 *    novo pode entrar num fechamento novo) e vira um `CommissionClawback`: os PRÓXIMOS
 *    fechamentos do mesmo profissional descontam o valor (`CommissionDeductionApplier`). O
 *    fechamento já feito não muda — closed/paid é imutável (regra de negócio 4 do doc 09).
 * Nada é apagado, nenhum valor fica negativo, e estornar de novo não faz nada.
 */
final class CommissionReversal
{
    /** @return list<array{settlement_item_id: int, sale_item_id: int, commission_amount: string, clawback_id: int}> */
    public function reverseForSale(Sale $sale, string $reason, User $user): array
    {
        $items = CommissionSettlementItem::query()
            ->whereIn('sale_item_id', $sale->items()->select('id'))
            ->whereNull('reversed_at')
            ->with('settlement')
            ->get();

        return $items->map(fn (CommissionSettlementItem $item): array => $this->reverseItem($item, $reason, $user->id))->all();
    }

    /**
     * Agendamento que deixou de estar concluído (PLANO 2.6): só a comissão fechada pelo gatilho
     * `on_execution` perde o fato que a fez nascer — a de venda/recebimento do mesmo item segue
     * valendo. O gatilho lido é o da regra GRAVADA no fechamento (inclusive apagada depois),
     * não o da regra de hoje: o fechamento é documento (regra de negócio 4 do doc 09).
     *
     * @return list<array{settlement_item_id: int, sale_item_id: int, commission_amount: string, clawback_id: int}>
     */
    public function reverseForExecution(Appointment $appointment, string $reason, ?int $userId): array
    {
        $items = CommissionSettlementItem::query()
            ->whereIn('sale_item_id', SaleItem::query()->where('appointment_id', $appointment->id)->select('id'))
            ->whereNull('reversed_at')
            ->whereIn('commission_rule_id', CommissionRule::withTrashed()->where('trigger', CommissionTrigger::ON_EXECUTION->value)->select('id'))
            ->with('settlement')
            ->get();

        return $items->map(fn (CommissionSettlementItem $item): array => $this->reverseItem($item, $reason, $userId))->all();
    }

    /** @return array{settlement_item_id: int, sale_item_id: int, commission_amount: string, clawback_id: int} */
    private function reverseItem(CommissionSettlementItem $item, string $reason, ?int $userId): array
    {
        $item->forceFill(['reversed_at' => now(), 'reversal_reason' => $reason])->save();
        $clawback = CommissionClawback::create([
            'organization_id' => $item->settlement->organization_id,
            'professional_id' => $item->settlement->professional_id,
            'staff_id' => $item->settlement->staff_id,
            'commission_settlement_item_id' => $item->id,
            'sale_item_id' => $item->sale_item_id,
            'amount' => (string) $item->commission_amount,
            'reason' => $reason,
            'created_by' => $userId,
        ]);

        return [
            'settlement_item_id' => $item->id,
            'sale_item_id' => $item->sale_item_id,
            'commission_amount' => (string) $item->commission_amount,
            'clawback_id' => $clawback->id,
        ];
    }
}
