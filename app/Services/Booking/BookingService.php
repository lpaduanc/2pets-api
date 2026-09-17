<?php

namespace App\Services\Booking;

use App\DataTransferObjects\BookingRequestDTO;
use App\Enums\BookingSource;
use App\Enums\ServiceCategory;
use App\Events\AppointmentBooked;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentRescheduled;
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
        $service = Service::findOrFail($dto->serviceId);

        $this->assertServiceIsTutorBookable($service);
        $this->validateBookingRequest($dto);

        $appointment = Appointment::create([
            'professional_id' => $dto->professionalId,
            'client_id' => $dto->clientId,
            'pet_id' => $dto->petId,
            'service_id' => $dto->serviceId,
            'appointment_date' => $dto->appointmentDate,
            'duration' => $service->duration,
            // Achado ao migrar docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md:
            // este `create()` nunca preenchia `type`, então TODO agendamento feito pelo tutor
            // caía no DEFAULT da coluna (`consultation`), não importa o serviço escolhido —
            // um exame de imagem virava "consulta" para `MedicalRecordEncounterResolver`.
            // `services.category` é a mesma taxonomia de `appointments.type` desde a fusão.
            'type' => $service->category,
            'status' => 'pending',
            'booking_source' => BookingSource::CLIENT->value,
            'requires_confirmation' => true,
            'notes' => $dto->notes,
        ]);

        AppointmentBooked::dispatch($appointment);

        return $appointment;
    }

    public function confirmBooking(int $appointmentId): Appointment
    {
        $appointment = Appointment::findOrFail($appointmentId);

        $appointment->update([
            'status' => 'scheduled',
            'confirmed_at' => Carbon::now(),
        ]);

        AppointmentConfirmed::dispatch($appointment);

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

        AppointmentCancelled::dispatch($appointment, $reason);

        return $appointment;
    }

    public function rescheduleBooking(
        int $appointmentId,
        Carbon $newDate
    ): Appointment {
        $appointment = Appointment::findOrFail($appointmentId);

        $this->validateReschedule($appointment, $newDate);

        $previousDate = $appointment->appointment_date->toIso8601String();

        $appointment->update([
            'appointment_date' => $newDate,
            'status' => 'pending',
            'confirmed_at' => null,
        ]);

        AppointmentRescheduled::dispatch($appointment, $previousDate);

        return $appointment;
    }

    /**
     * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §4:
     * `boarding`/`hospitalization` não usam o seletor de horário — o tutor só solicita,
     * o profissional confirma. Regra de negócio, não formato de request: fica aqui porque
     * `createBooking()` é o único caminho de escrita de agendamento do tutor
     * (`Api\Public\BookingController::book`) — travar só na validação HTTP deixaria a porta
     * aberta para qualquer outro controller que venha a chamar este service direto.
     */
    private function assertServiceIsTutorBookable(Service $service): void
    {
        $category = ServiceCategory::from($service->category);

        if (! $category->isSelfBookableByTutor()) {
            throw new \InvalidArgumentException(
                'Este serviço não pode ser agendado diretamente pelo tutor. Entre em contato para solicitar — o profissional confirma a reserva.'
            );
        }
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

        if (! $isSlotAvailable) {
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
