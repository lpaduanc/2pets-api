<?php

namespace App\Observers;

use App\Enums\AppointmentStatus;
use App\Events\Sales\CommissionRecalculated;
use App\Models\Appointment;
use App\Models\SaleItem;
use App\Services\Commercial\Reversal\CommissionReversal;

/**
 * Conclusão do agendamento e a comissão `on_execution` (PLANO 2.6). Observer, como a baixa de
 * insumos (`AppointmentSupplyConsumptionObserver`), porque há mais de uma porta para o status.
 *
 *  - Carimba `completed_at` ao entrar em `completed` e limpa ao sair: é a data em que a comissão
 *    `on_execution` nasce.
 *  - Saiu de `completed` (ou foi apagado concluído): a comissão `on_execution` já fechada vira
 *    estorno a descontar no próximo fechamento (`CommissionReversal`, mecanismo da 2.3); a ainda
 *    não fechada sai sozinha do "em aberto". A máquina de estados trata `completed` como
 *    terminal hoje, mas uma correção administrativa ou porta nova não pode deixar comissão
 *    paga sobre serviço que não aconteceu.
 *  - Qualquer mudança de status recalcula a rentabilidade das vendas ligadas: o `on_execution`
 *    de agendamento cancelado deixa de ser custo projetado (1.5).
 */
final class AppointmentCompletionObserver
{
    public function __construct(private readonly CommissionReversal $commissions) {}

    public function saving(Appointment $appointment): void
    {
        if (! $appointment->isDirty('status')) {
            return;
        }

        $appointment->completed_at = $appointment->status === AppointmentStatus::COMPLETED->value ? now() : null;
    }

    public function updated(Appointment $appointment): void
    {
        if (! $appointment->wasChanged('status')) {
            return;
        }

        if ($appointment->getOriginal('status') === AppointmentStatus::COMPLETED->value) {
            $this->reverse($appointment, 'Agendamento deixou de estar concluído');
        }

        $this->recomputeLinkedSales($appointment);
    }

    public function deleted(Appointment $appointment): void
    {
        if ($appointment->status === AppointmentStatus::COMPLETED->value) {
            $this->reverse($appointment, 'Agendamento concluído foi excluído');
        }

        $this->recomputeLinkedSales($appointment);
    }

    private function reverse(Appointment $appointment, string $reason): void
    {
        $this->commissions->reverseForExecution($appointment, $reason, auth()->id());
    }

    private function recomputeLinkedSales(Appointment $appointment): void
    {
        SaleItem::query()->where('appointment_id', $appointment->id)->distinct()->pluck('sale_id')
            ->each(fn (int $saleId) => CommissionRecalculated::dispatch($saleId));
    }
}
