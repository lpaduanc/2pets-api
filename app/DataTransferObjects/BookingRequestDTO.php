<?php

namespace App\DataTransferObjects;

use Carbon\Carbon;

/**
 * `professionalId` é `null` no modo "qualquer profissional disponível" (Fase 2, item 6):
 * `organizationId` chega preenchido nesse caso, e `BookingService` resolve um profissional
 * concreto antes de gravar o agendamento — nunca fica `null` no `Appointment` criado.
 */
final readonly class BookingRequestDTO
{
    public function __construct(
        public ?int $professionalId,
        public int $clientId,
        public int $serviceId,
        public ?int $petId,
        public Carbon $appointmentDate,
        public string $notes = '',
        public ?int $organizationId = null,
        public ?int $locationId = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            professionalId: isset($data['professional_id']) ? (int) $data['professional_id'] : null,
            clientId: (int) $data['client_id'],
            serviceId: (int) $data['service_id'],
            petId: isset($data['pet_id']) ? (int) $data['pet_id'] : null,
            appointmentDate: Carbon::parse($data['appointment_date']),
            notes: $data['notes'] ?? '',
            organizationId: isset($data['organization_id']) ? (int) $data['organization_id'] : null,
            locationId: isset($data['location_id']) ? (int) $data['location_id'] : null,
        );
    }
}
