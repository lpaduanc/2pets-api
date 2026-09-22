<?php

namespace App\Services\Booking;

use App\DataTransferObjects\AvailabilityWindow;
use App\Models\Availability;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Duas janelas do mesmo profissional, no mesmo dia da semana e no mesmo local, não podem
 * se sobrepor" — a mesma regra vale tanto para um `POST`/`PUT` isolado quanto para a
 * substituição da semana inteira, então mora numa classe própria em vez de duplicada em
 * cada Form Request.
 *
 * `location_id` nulo é um "local" só seu: duas janelas sem local não se sobrepõem com uma
 * janela QUE TEM local, mesmo dia/horário — são grades diferentes.
 */
final class AvailabilityOverlapChecker
{
    public function overlaps(AvailabilityWindow $window, ?int $excludeId = null): bool
    {
        return Availability::query()
            ->where('professional_id', $window->professionalId)
            ->where('day_of_week', $window->dayOfWeek)
            ->atLocation($window->locationId)
            ->when($excludeId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $excludeId))
            ->where('start_time', '<', $window->endTime)
            ->where('end_time', '>', $window->startTime)
            ->exists();
    }
}
