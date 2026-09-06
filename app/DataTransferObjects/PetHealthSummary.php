<?php

namespace App\DataTransferObjects;

use App\Models\Pet;
use Illuminate\Support\Collection;

/**
 * One pet plus the health events that are due within the requested window.
 */
final readonly class PetHealthSummary
{
    /**
     * @param  Collection<int, PetHealthEvent>  $events
     */
    public function __construct(
        public Pet $pet,
        public Collection $events,
    ) {}

    public function overdueCount(): int
    {
        return $this->events->filter(fn (PetHealthEvent $event) => $event->isOverdue())->count();
    }

    public function dueSoonCount(): int
    {
        return $this->events->filter(fn (PetHealthEvent $event) => $event->isDueSoon())->count();
    }
}
