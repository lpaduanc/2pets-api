<?php

namespace App\Services\Medical;

use App\DataTransferObjects\PetHealthEvent;
use App\DataTransferObjects\PetHealthSummaries;
use App\DataTransferObjects\PetHealthSummary;
use App\Enums\HealthEventType;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\User;
use App\Models\Vaccination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Builds the "what is due for my pets" roll-up in a fixed number of queries,
 * whatever the number of pets: one for the pets, one for the vaccinations of all
 * of them, one for the dewormings of all of them (constrained eager loading).
 *
 * Scope is always the tutor's own pets. A vet holding a PetVetAccess grant reads
 * the pet through the per-pet endpoints, never through this aggregate.
 */
final class PetHealthSummaryService
{
    /** Matches what the dashboard renders per pet card. */
    private const MAX_EVENTS_PER_PET = 6;

    /** Only what the roll-up card shows — no medical detail leaves this endpoint. */
    private const PET_COLUMNS = ['id', 'user_id', 'name', 'species', 'breed', 'image_url'];

    public function forTutor(User $tutor, int $windowDays): PetHealthSummaries
    {
        $dueUntil = CarbonImmutable::today()->addDays($windowDays);

        $summaries = $this->loadPetsWithDueRecords($tutor, $dueUntil)
            ->map(fn (Pet $pet) => new PetHealthSummary($pet, $this->eventsFor($pet)));

        return new PetHealthSummaries($summaries->all());
    }

    /**
     * @return EloquentCollection<int, Pet>
     */
    private function loadPetsWithDueRecords(User $tutor, CarbonImmutable $dueUntil): EloquentCollection
    {
        return Pet::query()
            ->select(self::PET_COLUMNS)
            ->where('user_id', $tutor->id)
            ->with([
                'vaccinations' => fn (HasMany $query) => $query
                    ->select(['id', 'pet_id', 'vaccine_name', 'next_dose_date'])
                    ->latestPerType()
                    ->whereNotNull('next_dose_date')
                    ->whereDate('next_dose_date', '<=', $dueUntil)
                    ->orderBy('next_dose_date'),
                'dewormings' => fn (HasMany $query) => $query
                    ->select(['id', 'pet_id', 'product_name', 'next_date'])
                    ->whereNotNull('next_date')
                    ->whereDate('next_date', '<=', $dueUntil)
                    ->orderBy('next_date'),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, PetHealthEvent>
     */
    private function eventsFor(Pet $pet): Collection
    {
        return $this->vaccinationEvents($pet)
            ->concat($this->dewormingEvents($pet))
            ->sortBy(fn (PetHealthEvent $event) => $event->dueDate->getTimestamp())
            ->take(self::MAX_EVENTS_PER_PET)
            ->values();
    }

    /**
     * @return Collection<int, PetHealthEvent>
     */
    private function vaccinationEvents(Pet $pet): Collection
    {
        return $pet->vaccinations->map(fn (Vaccination $vaccination) => new PetHealthEvent(
            HealthEventType::VACCINATION,
            $vaccination->id,
            $vaccination->vaccine_name,
            CarbonImmutable::parse($vaccination->next_dose_date),
        ));
    }

    /**
     * @return Collection<int, PetHealthEvent>
     */
    private function dewormingEvents(Pet $pet): Collection
    {
        return $pet->dewormings->map(fn (PetDeworming $deworming) => new PetHealthEvent(
            HealthEventType::DEWORMING,
            $deworming->id,
            $deworming->product_name,
            CarbonImmutable::parse($deworming->next_date),
        ));
    }
}
