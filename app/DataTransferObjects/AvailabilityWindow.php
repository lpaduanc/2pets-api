<?php

namespace App\DataTransferObjects;

/**
 * Uma janela de disponibilidade (`availabilities`), antes de virar `Model` — usada tanto na
 * validação de sobreposição (`AvailabilityOverlapChecker`) quanto ao montar o payload de
 * `POST`/`PUT` no `AvailabilityManagementService`.
 */
final readonly class AvailabilityWindow
{
    public function __construct(
        public int $professionalId,
        public ?int $locationId,
        public int $dayOfWeek,
        public string $startTime,
        public string $endTime,
    ) {}

    /**
     * @param  array{professional_id: int, location_id: int|null, day_of_week: int, start_time: string, end_time: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            professionalId: $data['professional_id'],
            locationId: $data['location_id'],
            dayOfWeek: $data['day_of_week'],
            startTime: $data['start_time'],
            endTime: $data['end_time'],
        );
    }
}
