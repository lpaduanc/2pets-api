<?php

namespace App\DataTransferObjects;

use Carbon\Carbon;

final readonly class BookingRequestDTO
{
    public function __construct(
        public int $professionalId,
        public int $clientId,
        public int $serviceId,
        public ?int $petId,
        public Carbon $appointmentDate,
        public string $notes = '',
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            professionalId: (int) $data['professional_id'],
            clientId: (int) $data['client_id'],
            serviceId: (int) $data['service_id'],
            petId: isset($data['pet_id']) ? (int) $data['pet_id'] : null,
            appointmentDate: Carbon::parse($data['appointment_date']),
            notes: $data['notes'] ?? '',
        );
    }
}

