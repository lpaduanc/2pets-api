<?php

namespace App\Services\Appointment;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentRejected;
use App\Models\Appointment;

/**
 * Fase 4 do fluxo de agendamento: `POST professional/appointments/{id}/confirm|reject`.
 * Única regra de transição continua sendo `AppointmentStatusTransitionService` — este
 * service só orquestra "qual status" + "qual evento" para cada ação, nunca decide sozinho
 * se a transição é permitida.
 *
 * `BookingService::confirmBooking()` (código morto, achado nesta fase: gravava
 * `status = scheduled`, não `confirmed`, e não era chamado por nenhuma rota) foi REMOVIDO
 * em vez de reaproveitado — duplicava exatamente esta responsabilidade, com um bug que
 * nunca foi pego por não ter caminho de execução nenhum.
 */
final class AppointmentConfirmationService
{
    public function __construct(
        private readonly AppointmentStatusTransitionService $statusTransitionService,
        private readonly AppointmentDepositService $depositService,
    ) {}

    /**
     * Fase 6: sinal (se aplicável ao serviço/estabelecimento) só é cobrado DEPOIS que a
     * transição de status já é válida — nunca cobra um agendamento que não podia ser
     * confirmado.
     */
    public function confirm(Appointment $appointment): Appointment
    {
        $this->transitionTo($appointment, AppointmentStatus::CONFIRMED);

        $this->depositService->chargeIfApplicable($appointment);

        AppointmentConfirmed::dispatch($appointment);

        return $appointment;
    }

    public function reject(Appointment $appointment, ?string $reason): Appointment
    {
        $this->transitionTo($appointment, AppointmentStatus::CANCELLED, $reason);

        AppointmentRejected::dispatch($appointment, $reason);

        return $appointment;
    }

    private function transitionTo(Appointment $appointment, AppointmentStatus $target, ?string $cancellationReason = null): void
    {
        $data = $this->statusTransitionService->prepareTransition($appointment, ['status' => $target->value]);

        if ($cancellationReason !== null) {
            $data['cancellation_reason'] = $cancellationReason;
        }

        $appointment->update($data);
    }
}
