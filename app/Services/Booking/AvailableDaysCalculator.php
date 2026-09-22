<?php

namespace App\Services\Booking;

use App\DataTransferObjects\Booking\AvailabilityDaysQuery;
use App\Models\Availability;
use App\Models\BlockedTime;
use App\Models\Organization;
use App\Services\Organization\OrganizationTeamService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "Quais dias do mês têm ao menos um horário livre" (Fase 2, item 5 — o frontend usa isso
 * para desabilitar dia no calendário). Resolvido por DIA DA SEMANA + bloqueio, nunca
 * gerando slot a slot dos até 31 dias do mês: no pior caso (organização com equipe) são 3
 * consultas (janelas ativas, bloqueios do mês, membros bookáveis) e o resto é comparação em
 * memória — medido em `docs`/relato da tarefa, não em loop de `AvailabilityService`.
 */
final class AvailableDaysCalculator
{
    public function __construct(
        private readonly OrganizationTeamService $teamService,
        private readonly StaffTimeOffChecker $staffTimeOffChecker,
    ) {}

    /**
     * @return list<string>
     */
    public function daysWithAvailability(AvailabilityDaysQuery $query): array
    {
        $professionalIds = $this->resolveProfessionalIds($query);

        if ($professionalIds === []) {
            return [];
        }

        $windowsByProfessionalAndDay = $this->windowsByProfessionalAndDay($professionalIds, $query);
        $blockedTimesByProfessional = $this->blockedTimesInMonth($professionalIds, $query);

        return $this->datesInMonth($query->month)
            ->filter(fn (Carbon $date): bool => $this->isDateAvailable(
                $date,
                $professionalIds,
                $windowsByProfessionalAndDay,
                $blockedTimesByProfessional,
                $query->organizationId,
            ))
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function resolveProfessionalIds(AvailabilityDaysQuery $query): array
    {
        if ($query->professionalId !== null) {
            return [$query->professionalId];
        }

        if ($query->organizationId === null) {
            return [];
        }

        $organization = Organization::find($query->organizationId);

        if ($organization === null) {
            return [];
        }

        return $this->teamService->bookableMembers($organization, $query->serviceId)->pluck('id')->all();
    }

    /**
     * @param  list<int>  $professionalIds
     * @return Collection<string, Collection<int, Availability>>
     */
    private function windowsByProfessionalAndDay(array $professionalIds, AvailabilityDaysQuery $query): Collection
    {
        return Availability::query()
            ->whereIn('professional_id', $professionalIds)
            ->where('is_active', true)
            ->when($query->locationId !== null, fn ($q) => $q->where('location_id', $query->locationId))
            ->when(
                $query->locationId === null && $query->organizationId !== null,
                fn ($q) => $q->where('organization_id', $query->organizationId)
            )
            ->get()
            ->groupBy(fn (Availability $availability): string => "{$availability->professional_id}-{$availability->day_of_week}");
    }

    /**
     * Item 21 do backlog gap-simplesvet — um bloqueio amplo (`professional_id = null`,
     * `organization_id`/`location_id` preenchido) some do resultado de
     * `whereIn('professional_id', ...)`; ele é buscado à parte e somado à lista de cada
     * profissional do escopo, mesma correção aplicada em `AvailabilityService`.
     *
     * @param  list<int>  $professionalIds
     * @return Collection<int, Collection<int, BlockedTime>>
     */
    private function blockedTimesInMonth(array $professionalIds, AvailabilityDaysQuery $query): Collection
    {
        $start = $query->month->copy()->startOfMonth();
        $end = $query->month->copy()->endOfMonth();

        $specific = BlockedTime::whereIn('professional_id', $professionalIds)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>=', $start)
            ->get()
            ->groupBy('professional_id');

        $broad = $this->broadBlockedTimesInMonth($query, $start, $end);
        if ($broad->isEmpty()) {
            return $specific;
        }

        return collect($professionalIds)->mapWithKeys(
            fn (int $id): array => [$id => $specific->get($id, collect())->concat($broad)]
        );
    }

    /**
     * @return Collection<int, BlockedTime>
     */
    private function broadBlockedTimesInMonth(AvailabilityDaysQuery $query, Carbon $start, Carbon $end): Collection
    {
        $scope = $this->broadBlockScope($query);
        if ($scope === null) {
            return collect();
        }

        [$column, $value] = $scope;

        return BlockedTime::whereNull('professional_id')
            ->where($column, $value)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>=', $start)
            ->get();
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function broadBlockScope(AvailabilityDaysQuery $query): ?array
    {
        if ($query->locationId !== null) {
            return ['location_id', $query->locationId];
        }

        if ($query->organizationId !== null) {
            return ['organization_id', $query->organizationId];
        }

        return null;
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function datesInMonth(Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $today = Carbon::today();
        $firstRelevant = $start->lessThan($today) ? $today->copy() : $start->copy();
        $end = $month->copy()->endOfMonth();

        $dates = collect();

        for ($date = $firstRelevant->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $dates->push($date->copy());
        }

        return $dates;
    }

    /**
     * @param  list<int>  $professionalIds
     * @param  Collection<string, Collection<int, Availability>>  $windowsByProfessionalAndDay
     * @param  Collection<int, Collection<int, BlockedTime>>  $blockedTimesByProfessional
     */
    private function isDateAvailable(
        Carbon $date,
        array $professionalIds,
        Collection $windowsByProfessionalAndDay,
        Collection $blockedTimesByProfessional,
        ?int $organizationId,
    ): bool {
        foreach ($professionalIds as $professionalId) {
            if ($this->hasFreeWindowThatDay($date, $professionalId, $windowsByProfessionalAndDay, $blockedTimesByProfessional, $organizationId)) {
                return true;
            }
        }

        return false;
    }

    private function hasFreeWindowThatDay(
        Carbon $date,
        int $professionalId,
        Collection $windowsByProfessionalAndDay,
        Collection $blockedTimesByProfessional,
        ?int $organizationId,
    ): bool {
        if ($this->staffTimeOffChecker->isOnApprovedTimeOff($professionalId, $organizationId, $date)) {
            return false;
        }

        $windows = $windowsByProfessionalAndDay->get("{$professionalId}-{$date->dayOfWeek}", collect());
        $blocks = $blockedTimesByProfessional->get($professionalId, collect());

        return $windows->contains(fn (Availability $window): bool => ! $this->isWindowFullyBlocked($date, $window, $blocks));
    }

    /**
     * @param  Collection<int, BlockedTime>  $blocksForProfessional
     */
    private function isWindowFullyBlocked(Carbon $date, Availability $window, Collection $blocksForProfessional): bool
    {
        $windowStart = Carbon::parse($date->format('Y-m-d').' '.$window->start_time);
        $windowEnd = Carbon::parse($date->format('Y-m-d').' '.$window->end_time);

        return $blocksForProfessional->contains(
            fn (BlockedTime $block): bool => $block->start_datetime->lessThanOrEqualTo($windowStart)
                && $block->end_datetime->greaterThanOrEqualTo($windowEnd)
        );
    }
}
