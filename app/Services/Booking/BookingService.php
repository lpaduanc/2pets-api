<?php

namespace App\Services\Booking;

use App\DataTransferObjects\BookingRequestDTO;
use App\Enums\BookingSource;
use App\Models\Appointment;
use App\Models\Service;
use Carbon\Carbon;

final class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availabilityService
    ) {}

    public function createBooking(BookingRequestDTO $dto): Appointment
    {
        $this->validateBookingRequest($dto);

        $service = Service::findOrFail($dto->serviceId);

        $appointment = Appointment::create([
            'professional_id' => $dto->professionalId,
            'client_id' => $dto->clientId,
            'pet_id' => $dto->petId,
            'service_id' => $dto->serviceId,
            'appointment_date' => $dto->appointmentDate,
            'duration' => $service->duration,
            'status' => 'pending',
            'booking_source' => BookingSource::CLIENT->value,
            'requires_confirmation' => true,
            'notes' => $dto->notes,
        ]);

        // TODO: Dispatch AppointmentBooked event (Phase 3)

        return $appointment;
    }

    public function confirmBooking(int $appointmentId): Appointment
    {
        $appointment = Appointment::findOrFail($appointmentId);

        $appointment->update([
            'status' => 'scheduled',
            'confirmed_at' => Carbon::now(),
        ]);

        // TODO: Dispatch AppointmentConfirmed event (Phase 3)

        return $appointment;
    }

    public function cancelBooking(int $appointmentId, string $reason): Appointment
    {
        $appointment = Appointment::findOrFail($appointmentId);

        $appointment->update([
            'status' => 'cancelled',
            'cancelled_at' => Carbon::now(),
            'cancellation_reason' => $reason,
        ]);

        // TODO: Dispatch AppointmentCancelled event (Phase 3)
        // TODO: Check waitlist and notify (Phase 3)

        return $appointment;
    }

    public function rescheduleBooking(
        int $appointmentId,
        Carbon $newDate
    ): Appointment {
        $appointment = Appointment::findOrFail($appointmentId);

        $this->validateReschedule($appointment, $newDate);

        $appointment->update([
            'appointment_date' => $newDate,
            'status' => 'pending',
            'confirmed_at' => null,
        ]);

        // TODO: Dispatch AppointmentRescheduled event (Phase 3)

        return $appointment;
    }

    private function validateBookingRequest(BookingRequestDTO $dto): void
    {
        if ($dto->appointmentDate->isPast()) {
            throw new \InvalidArgumentException('Cannot book appointments in the past');
        }

        $availableSlots = $this->availabilityService->getAvailableSlots(
            $dto->professionalId,
            $dto->appointmentDate,
            $dto->serviceId
        );

        $isSlotAvailable = $availableSlots->contains(function ($slot) use ($dto) {
            return $slot->startTime->equalTo($dto->appointmentDate);
        });

        if (!$isSlotAvailable) {
            throw new \InvalidArgumentException('Selected time slot is not available');
        }
    }

    private function validateReschedule(Appointment $appointment, Carbon $newDate): void
    {
        if ($newDate->isPast()) {
            throw new \InvalidArgumentException('Cannot reschedule to past date');
        }

        if ($appointment->status === 'cancelled') {
            throw new \InvalidArgumentException('Cannot reschedule cancelled appointment');
        }

        if ($appointment->status === 'completed') {
            throw new \InvalidArgumentException('Cannot reschedule completed appointment');
        }
    }
}

