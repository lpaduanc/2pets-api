<?php

namespace App\Services\Booking;

use App\DataTransferObjects\AggregatedTimeSlot;
use App\DataTransferObjects\Booking\AvailabilityContext;
use App\DataTransferObjects\BookingRequestDTO;
use App\Enums\BookingSource;
use App\Enums\ServiceCategory;
use App\Events\AppointmentBooked;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\OrganizationMember;
use App\Models\Service;
use App\Support\ServiceNameNormalizer;
use Carbon\Carbon;

final class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly AvailabilityAggregationService $availabilityAggregationService,
    ) {}

    /**
     * Fase 2, item 6: `dto->professionalId` pode vir `null` (modo "qualquer profissional
     * disponível" — `organizationId` obrigatório nesse caso). Sempre que a organização é
     * informada — profissional escolhido pelo tutor OU resolvido aqui — o servidor confirma
     * que ele pertence a ela e executa o serviço pedido, nunca confiando só no payload.
     */
    public function createBooking(BookingRequestDTO $dto): Appointment
    {
        $service = Service::findOrFail($dto->serviceId);

        $this->assertServiceIsTutorBookable($service);

        $professionalId = $this->resolveProfessionalId($dto);

        if ($dto->organizationId !== null) {
            $this->assertProfessionalBelongsToOrganization($professionalId, $dto->organizationId, $service);
        }

        $this->validateBookingRequest($dto, $professionalId);

        $appointment = Appointment::create([
            'professional_id' => $professionalId,
            'organization_id' => $dto->organizationId,
            'location_id' => $dto->locationId,
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

    /**
     * `dto->professionalId` nulo (modo "qualquer profissional") é resolvido consultando o
     * modo agregado pelo horário exato pedido — o mesmo cálculo que
     * `GET /booking/availability` (modo agregado) já expõe ao app, então o profissional
     * devolvido aqui é sempre um dos que o app já mostrou como livre.
     */
    private function resolveProfessionalId(BookingRequestDTO $dto): int
    {
        if ($dto->professionalId !== null) {
            return $dto->professionalId;
        }

        if ($dto->organizationId === null) {
            throw new \InvalidArgumentException('Informe professional_id ou organization_id para agendar.');
        }

        $slots = $this->availabilityAggregationService->getAggregatedSlots(
            $dto->organizationId,
            $dto->appointmentDate,
            $dto->serviceId,
            $dto->locationId,
        );

        $matched = $slots->first(
            fn (AggregatedTimeSlot $slot): bool => $slot->startTime->equalTo($dto->appointmentDate)
        );

        if ($matched === null) {
            throw new \InvalidArgumentException('Nenhum profissional da equipe está disponível neste horário.');
        }

        return $matched->professionalId;
    }

    /**
     * Nunca confia no `professional_id`/`organization_id` do payload: confirma que o
     * profissional (escolhido pelo tutor OU resolvido no modo agregado) é membro ativo
     * DESTA organização e que o serviço pedido é dele — `services.professional_id` é 1:1.
     */
    /**
     * Achado real (Fase 6): esta checagem comparava `service->professional_id ===
     * $professionalId` (id exato), enquanto `OrganizationServiceCatalog`/
     * `OrganizationTeamService` agrupam/filtram "quem oferece este serviço" por NOME
     * normalizado (`ServiceNameNormalizer`) — os dois critérios discordavam. Numa clínica
     * onde dois vets cadastram "Consulta Geral" com nomes iguais e ids diferentes (o caso
     * que o catálogo agrupado existe para cobrir), o app mostra os dois como oferecendo o
     * mesmo serviço, mas o agendamento rejeitava um deles com 422. Corrigido para usar o
     * MESMO critério (nome normalizado, dentro da mesma organização) — nunca mais
     * `professional_id` exato aqui.
     */
    private function assertProfessionalBelongsToOrganization(int $professionalId, int $organizationId, Service $service): void
    {
        $isActiveMember = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $professionalId)
            ->where('is_active', true)
            ->exists();

        if (! $isActiveMember) {
            throw new \InvalidArgumentException('Este profissional não pertence ao estabelecimento informado.');
        }

        if (! $this->professionalOffersService($professionalId, $organizationId, $service)) {
            throw new \InvalidArgumentException('Este profissional não executa o serviço selecionado.');
        }
    }

    private function professionalOffersService(int $professionalId, int $organizationId, Service $service): bool
    {
        $normalizedTarget = ServiceNameNormalizer::normalize($service->name);

        return Service::query()
            ->where('professional_id', $professionalId)
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->get()
            ->contains(fn (Service $candidate): bool => ServiceNameNormalizer::normalize($candidate->name) === $normalizedTarget);
    }

    private function validateBookingRequest(BookingRequestDTO $dto, int $professionalId): void
    {
        if ($dto->appointmentDate->isPast()) {
            throw new \InvalidArgumentException('Cannot book appointments in the past');
        }

        $availableSlots = $this->availabilityService->getAvailableSlots(
            $professionalId,
            $dto->appointmentDate,
            $dto->serviceId,
            new AvailabilityContext($dto->organizationId, $dto->locationId),
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
