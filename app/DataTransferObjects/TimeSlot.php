<?php

namespace App\DataTransferObjects;

use Carbon\Carbon;

final readonly class TimeSlot
{
    public function __construct(
        public Carbon $startTime,
        public Carbon $endTime,
        public bool $isAvailable,
    ) {}

    public function toArray(): array
    {
        return [
            'start_time' => $this->startTime->toISOString(),
            'end_time' => $this->endTime->toISOString(),
            'is_available' => $this->isAvailable,
        ];
    }
}

