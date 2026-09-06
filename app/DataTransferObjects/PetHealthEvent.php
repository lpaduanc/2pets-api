<?php

namespace App\DataTransferObjects;

use App\Enums\HealthEventLevel;
use App\Enums\HealthEventType;
use Carbon\CarbonImmutable;

/**
 * A single "something is due for this pet" entry of the tutor health roll-up.
 *
 * `daysUntil` and `level` are derived from the due date at construction time so
 * every consumer (API resource, counters) reads the same classification.
 */
final readonly class PetHealthEvent
{
    public int $daysUntil;

    public HealthEventLevel $level;

    public function __construct(
        public HealthEventType $type,
        public int $recordId,
        public string $reference,
        public CarbonImmutable $dueDate,
    ) {
        $this->daysUntil = (int) CarbonImmutable::today()->diffInDays($this->dueDate, false);
        $this->level = HealthEventLevel::fromDaysUntil($this->daysUntil);
    }

    /**
     * Stable identity for list rendering — record ids are only unique per type.
     */
    public function key(): string
    {
        return "{$this->type->value}-{$this->recordId}";
    }

    public function isOverdue(): bool
    {
        return $this->level === HealthEventLevel::OVERDUE;
    }

    public function isDueSoon(): bool
    {
        return $this->level === HealthEventLevel::DUE_SOON;
    }
}
