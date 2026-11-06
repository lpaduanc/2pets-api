<?php

namespace App\Services\Commercial;

use App\Enums\AppointmentStatus;
use App\Enums\CommissionTrigger;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\CommissionRule;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReceipt;
use Carbon\CarbonImmutable;

/**
 * Quando a comissão de um item nasce, por gatilho da regra (PLANO 2.6).
 *
 * Piso comum aos três gatilhos (regra de negócio 1 do doc 09 e decisão antiga de "venda
 * finalizada sem pagamento fica fora"): a venda precisa estar `paid` e ter ao menos um
 * recebimento valendo. Fica de fora a venda de R$ 0,00 fechada sem recebimento (1.4) e a venda a
 * prazo (fiado/crediário) enquanto não quitada. O gatilho só escolhe a DATA do fato:
 *  - on_receipt: o último recebimento valendo (o comportamento de sempre);
 *  - on_sale: a data da venda (`sold_at`) — a venda de 28/10 paga em 03/11 entra no fechamento
 *    de outubro feito depois do pagamento;
 *  - on_execution: a conclusão do agendamento vinculado (`appointments.completed_at`); sem
 *    agendamento concluído não nasce. Serviço sem agendamento (e produto) cai em on_sale.
 * Item sem regra usa o `commission_percent` congelado e segue o recebimento, como antes.
 */
final class CommissionEligibility
{
    public function effectiveTrigger(SaleItem $item, ?CommissionRule $rule): CommissionTrigger
    {
        $trigger = $rule?->trigger ?? CommissionTrigger::ON_RECEIPT;

        if ($trigger === CommissionTrigger::ON_EXECUTION && $item->appointment_id === null) {
            return CommissionTrigger::ON_SALE;
        }

        return $trigger;
    }

    /** Data em que a comissão nasceu; `null` enquanto não nasceu (ou se nunca vai nascer). */
    public function bornAt(SaleItem $item, CommissionTrigger $trigger): ?CarbonImmutable
    {
        $sale = $item->sale;
        $lastReceipt = $this->lastActiveReceiptAt($sale);

        if ($lastReceipt === null || ! $this->isPaidSale($sale)) {
            return null;
        }

        return match ($trigger) {
            CommissionTrigger::ON_RECEIPT => $lastReceipt,
            CommissionTrigger::ON_SALE => $sale->sold_at === null ? $lastReceipt : CarbonImmutable::instance($sale->sold_at),
            CommissionTrigger::ON_EXECUTION => $this->executedAt($item),
        };
    }

    /**
     * `on_execution` cujo agendamento terminou sem ser realizado (cancelado, não compareceu ou
     * apagado): a comissão nunca vai ser devida, e a rentabilidade (1.5) não deve projetá-la.
     */
    public function willNeverBeDue(SaleItem $item, ?CommissionRule $rule): bool
    {
        if ($this->effectiveTrigger($item, $rule) !== CommissionTrigger::ON_EXECUTION) {
            return false;
        }

        $status = $item->appointment?->status;

        return $status === null || in_array($status, [AppointmentStatus::CANCELLED->value, AppointmentStatus::NO_SHOW->value], true);
    }

    private function isPaidSale(Sale $sale): bool
    {
        return $sale->status === SaleStatus::PAID && $sale->kind === SaleKind::SALE;
    }

    private function lastActiveReceiptAt(Sale $sale): ?CarbonImmutable
    {
        $last = $sale->receipts->filter(fn (SaleReceipt $receipt): bool => $receipt->reversed_at === null)->max('received_at');

        return $last === null ? null : CarbonImmutable::instance($last);
    }

    private function executedAt(SaleItem $item): ?CarbonImmutable
    {
        $appointment = $item->appointment;

        if ($appointment === null || $appointment->status !== AppointmentStatus::COMPLETED->value) {
            return null;
        }

        return CarbonImmutable::instance($appointment->completed_at ?? $appointment->updated_at);
    }
}
