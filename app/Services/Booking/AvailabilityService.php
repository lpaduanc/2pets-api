<?php

namespace App\Services\Booking;

use App\DataTransferObjects\TimeSlot;
use App\Models\Appointment;
use App\Models\Availability;
use App\Models\BlockedTime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class AvailabilityService
{
    public function getAvailableSlots(
        int $professionalId,
        Carbon $date,
        ?int $serviceId = null
    ): Collection {
        $professional = User::findOrFail($professionalId);
        $serviceDuration = $this->getServiceDuration($serviceId);

        $availability = $this->getAvailabilityForDay($professionalId, $date->dayOfWeek);

        if (! $availability) {
            return collect();
        }

        $slots = $this->generateTimeSlots(
            $date,
            $availability->start_time,
            $availability->end_time,
            $availability->slot_duration,
            $availability->buffer_time
        );

        return $this->filterAvailableSlots($professionalId, $slots, $serviceDuration);
    }

    private function getAvailabilityForDay(int $professionalId, int $dayOfWeek): ?Availability
    {
        return Availability::where('professional_id', $professionalId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->first();
    }

    private function getServiceDuration(?int $serviceId): int
    {
        if (! $serviceId) {
            return 30; // default duration
        }

        $service = \App\Models\Service::find($serviceId);

        return $service ? $service->duration : 30;
    }

    private function generateTimeSlots(
        Carbon $date,
        string $startTime,
        string $endTime,
        int $slotDuration,
        int $bufferTime
    ): Collection {
        $slots = collect();

        $start = Carbon::parse($date->format('Y-m-d').' '.$startTime);
        $end = Carbon::parse($date->format('Y-m-d').' '.$endTime);

        $totalMinutes = $slotDuration + $bufferTime;

        $current = $start->copy();

        while ($current->lessThan($end)) {
            $slotEnd = $current->copy()->addMinutes($slotDuration);

            if ($slotEnd->lessThanOrEqualTo($end)) {
                $slots->push(new TimeSlot(
                    startTime: $current->copy(),
                    endTime: $slotEnd->copy(),
                    isAvailable: true
                ));
            }

            $current->addMinutes($totalMinutes);
        }

        return $slots;
    }

    private function filterAvailableSlots(
        int $professionalId,
        Collection $slots,
        int $serviceDuration
    ): Collection {
        $existingAppointments = $this->getExistingAppointments($professionalId, $slots->first()->startTime);
        $blockedTimes = $this->getBlockedTimes($professionalId, $slots->first()->startTime);

        return $slots->map(function (TimeSlot $slot) use ($existingAppointments, $blockedTimes, $serviceDuration) {
            $slotEnd = $slot->startTime->copy()->addMinutes($serviceDuration);

            $isBlocked = $this->isTimeBlocked($slot->startTime, $slotEnd, $blockedTimes);
            $hasAppointment = $this->hasOverlappingAppointment($slot->startTime, $slotEnd, $existingAppointments);

            return new TimeSlot(
                startTime: $slot->startTime,
                endTime: $slot->endTime,
                isAvailable: ! $isBlocked && ! $hasAppointment
            );
        })->filter(fn (TimeSlot $slot) => $slot->isAvailable);
    }

    /**
     * `whereDate()` casts `appointment_date` (a datetime column), which blocks
     * index usage — a half-open range over the same calendar day is sargable
     * and returns the same rows.
     */
    private function getExistingAppointments(int $professionalId, Carbon $date): Collection
    {
        $startOfDay = $date->copy()->startOfDay();

        return Appointment::where('professional_id', $professionalId)
            ->where('appointment_date', '>=', $startOfDay)
            ->where('appointment_date', '<', $startOfDay->copy()->addDay())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get();
    }

    /**
     * Same sargability fix as above: "blocked time whose calendar-date range
     * covers $date" becomes a half-open range against `start_datetime`/`end_datetime`.
     */
    private function getBlockedTimes(int $professionalId, Carbon $date): Collection
    {
        $startOfDay = $date->copy()->startOfDay();

        return BlockedTime::where('professional_id', $professionalId)
            ->where('start_datetime', '<', $startOfDay->copy()->addDay())
            ->where('end_datetime', '>=', $startOfDay)
            ->get();
    }

    private function isTimeBlocked(Carbon $start, Carbon $end, Collection $blockedTimes): bool
    {
        return $blockedTimes->contains(function (BlockedTime $blocked) use ($start, $end) {
            return $start->lessThan($blocked->end_datetime) && $end->greaterThan($blocked->start_datetime);
        });
    }

    private function hasOverlappingAppointment(Carbon $start, Carbon $end, Collection $appointments): bool
    {
        return $appointments->contains(function (Appointment $appointment) use ($start, $end) {
            $appointmentStart = Carbon::parse($appointment->appointment_date);
            $appointmentEnd = $appointmentStart->copy()->addMinutes($appointment->duration ?? 30);

            return $start->lessThan($appointmentEnd) && $end->greaterThan($appointmentStart);
        });
    }
}
