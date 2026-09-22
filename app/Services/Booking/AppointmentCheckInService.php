<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Exceptions\Appointment\AppointmentNotCheckInableException;
use App\Models\Appointment;

/**
 * Check-in de recepção (item 21 do backlog gap-simplesvet, "fila do dia") — marca
 * `checked_in_at`, sem introduzir uma segunda máquina de estados paralela a
 * `AppointmentStatus` (o rótulo de fila é derivado, ver `Appointment::queueLabel()`).
 */
final class AppointmentCheckInService
{
    /**
     * @var list<string>
     */
    private const CHECK_INABLE_STATUSES = [
        AppointmentStatus::SCHEDULED->value,
        AppointmentStatus::CONFIRMED->value,
    ];

    public function checkIn(Appointment $appointment): Appointment
    {
        $this->guardSameDay($appointment);
        $this->guardCheckInableStatus($appointment);

        $appointment->update(['checked_in_at' => now()]);

        return $appointment;
    }

    private function guardSameDay(Appointment $appointment): void
    {
        if (! $appointment->appointment_date->isToday()) {
            throw AppointmentNotCheckInableException::wrongDay();
        }
    }

    private function guardCheckInableStatus(Appointment $appointment): void
    {
        if (! in_array($appointment->status, self::CHECK_INABLE_STATUSES, true)) {
            throw AppointmentNotCheckInableException::forStatus(AppointmentStatus::from($appointment->status));
        }
    }
}
