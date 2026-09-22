<?php

namespace App\DataTransferObjects;

use Carbon\Carbon;

/**
 * Um `TimeSlot` do modo agregado ("qualquer profissional disponível", Fase 2) — carrega
 * QUAL profissional atenderia, porque o slot não pertence a uma agenda só.
 */
final readonly class AggregatedTimeSlot
{
    public function __construct(
        public Carbon $startTime,
        public Carbon $endTime,
        public bool $isAvailable,
        public int $professionalId,
    ) {}

    /**
     * @return array{start_time: string, end_time: string, is_available: bool, professional_id: int}
     */
    public function toArray(): array
    {
        return [
            'start_time' => $this->startTime->toISOString(),
            'end_time' => $this->endTime->toISOString(),
            'is_available' => $this->isAvailable,
            'professional_id' => $this->professionalId,
        ];
    }
}
