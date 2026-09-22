<?php

namespace App\Services\Professional;

use App\DataTransferObjects\Professional\NewPatientAppointmentResult;
use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Orquestra `POST professional/appointments/new-patient` — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md`. Tutor, pet, agendamento
 * e o grant de acesso nascem juntos, numa única transação; o e-mail (efeito colateral externo)
 * só dispara DEPOIS do commit.
 */
final class NewPatientAppointmentService
{
    /**
     * `appointments.duration` é `NOT NULL` com `DEFAULT 30` no banco — mas o default só se
     * aplica quando a coluna fica FORA do `INSERT`. `Appointment::create()` sempre inclui todas
     * as chaves do array, então `'duration' => null` explícito estoura o NOT NULL em vez de
     * herdar o default (achado ao testar este endpoint por `curl` sem enviar `duration`, campo
     * opcional pelo contrato). Mesmo valor do default da coluna, para não introduzir uma
     * segunda fonte de verdade.
     */
    private const DEFAULT_DURATION_MINUTES = 30;

    public function __construct(
        private readonly TutorIdentityResolver $tutorResolver,
        private readonly NewPatientPetResolver $petResolver,
        private readonly NewPatientVetAccessGrantor $accessGrantor,
        private readonly NewPatientNotificationService $notificationService,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $professional, array $data, ?string $ipAddress, ?string $userAgent): NewPatientAppointmentResult
    {
        [$tutorResolution, $pet, $appointment] = DB::transaction(fn () => $this->persist($professional, $data));

        $claimLinkSent = $this->notificationService->notify(
            $tutorResolution->user,
            $professional,
            $pet,
            (bool) $data['marketing_opt_in'],
            $ipAddress,
            $userAgent,
        );

        return new NewPatientAppointmentResult($appointment, $pet, $tutorResolution->user, $claimLinkSent);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: \App\DataTransferObjects\Professional\TutorResolution, 1: Pet, 2: Appointment}
     */
    private function persist(User $professional, array $data): array
    {
        $tutorResolution = $this->tutorResolver->resolve($data['tutor_cpf'], [
            'name' => $data['tutor_name'],
            'email' => $data['tutor_email'] ?? null,
            'phone' => $data['tutor_phone'] ?? null,
        ]);

        $pet = $this->petResolver->resolve($tutorResolution->user, [
            'pet_name' => $data['pet_name'],
            'pet_species' => $data['pet_species'],
            'existing_pet_id' => $data['existing_pet_id'] ?? null,
            'create_new_pet' => (bool) ($data['create_new_pet'] ?? false),
        ]);

        $this->accessGrantor->grant($professional, $pet);

        $appointment = $this->createAppointment($professional, $tutorResolution->user, $pet, $data);

        return [$tutorResolution, $pet, $appointment];
    }

    /** @param  array<string, mixed>  $data */
    private function createAppointment(User $professional, User $tutor, Pet $pet, array $data): Appointment
    {
        return Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => $data['appointment_date'],
            'appointment_time' => $data['appointment_time'],
            'duration' => $data['duration'] ?? self::DEFAULT_DURATION_MINUTES,
            'type' => $data['type'],
            'appointment_type_id' => $data['appointment_type_id'] ?? null,
            'status' => AppointmentStatus::SCHEDULED->value,
            'reason' => $data['reason'] ?? null,
            'booking_source' => BookingSource::PROFESSIONAL->value,
        ]);
    }
}
