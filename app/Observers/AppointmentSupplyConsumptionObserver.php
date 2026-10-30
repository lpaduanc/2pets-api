<?php

namespace App\Observers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Commercial\ServiceCost\ServiceSupplyConsumer;

/**
 * Baixa dos insumos da ficha técnica quando o agendamento é CONCLUÍDO (PLANO 1.3). Observer, e
 * não chamada no controller, porque há mais de uma porta para `completed`
 * (`AppointmentController::update`, `ConsultationService::closeWithoutFinalizing`) e qualquer
 * porta nova precisa baixar igual. Só reage à MUDANÇA de status: o PUT idempotente que reenvia
 * `completed` não muda nada — e o consumidor ainda é idempotente por (referência, tipo).
 */
final class AppointmentSupplyConsumptionObserver
{
    public function __construct(private readonly ServiceSupplyConsumer $consumer) {}

    public function updated(Appointment $appointment): void
    {
        if (! $appointment->wasChanged('status') || $appointment->status !== AppointmentStatus::COMPLETED->value) {
            return;
        }

        $this->consumer->consumeForAppointment($appointment, auth()->id() ?? $appointment->professional_id);
    }
}
