<?php

namespace App\Services\Appointment;

use App\Enums\AppointmentStatus;
use App\Exceptions\Appointment\InvalidAppointmentStatusTransitionException;
use App\Models\Appointment;
use Carbon\Carbon;

/**
 * Única porta de escrita para `appointments.status` no fluxo do profissional
 * (`AppointmentController::update`). Garante que a transição é válida e carimba os timestamps
 * de ciclo de vida (`confirmed_at`, `cancelled_at`) de forma consistente — antes, o controller
 * gravava o status via `$appointment->update()` puro e nunca tocava nesses timestamps.
 */
final class AppointmentStatusTransitionService
{
    /**
     * Valida a transição e devolve `$data` com os timestamps de ciclo de vida já preenchidos,
     * prontos para um único `$appointment->update()` no controller.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareTransition(Appointment $appointment, array $data): array
    {
        $current = AppointmentStatus::from($appointment->status);
        $target = AppointmentStatus::from($data['status']);

        if (! $current->canTransitionTo($target)) {
            throw InvalidAppointmentStatusTransitionException::notAllowed($current, $target);
        }

        return [...$data, ...$this->lifecycleTimestamps($appointment, $target)];
    }

    /**
     * @return array<string, Carbon>
     */
    private function lifecycleTimestamps(Appointment $appointment, AppointmentStatus $target): array
    {
        return match ($target) {
            AppointmentStatus::CONFIRMED => ['confirmed_at' => $appointment->confirmed_at ?? Carbon::now()],
            AppointmentStatus::CANCELLED => ['cancelled_at' => $appointment->cancelled_at ?? Carbon::now()],
            default => [],
        };
    }
}
