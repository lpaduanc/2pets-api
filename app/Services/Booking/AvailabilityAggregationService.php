<?php

namespace App\Services\Booking;

use App\DataTransferObjects\AggregatedTimeSlot;
use App\DataTransferObjects\Booking\AvailabilityContext;
use App\DataTransferObjects\TimeSlot;
use App\Models\Appointment;
use App\Models\Organization;
use App\Services\Organization\OrganizationTeamService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Modo "qualquer profissional disponível" (Fase 2 do fluxo de agendamento): união dos
 * slots de toda a equipe bookável de uma organização, com um profissional concreto
 * atribuído a cada horário — nunca um slot "sem dono".
 *
 * Regra de desempate quando dois+ profissionais têm o MESMO horário livre: fica com quem
 * tem MENOS agendamentos naquele dia (`appointmentCounts()`) — distribui a demanda em vez
 * de sempre bater no primeiro profissional da lista.
 */
final class AvailabilityAggregationService
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly OrganizationTeamService $teamService,
    ) {}

    /**
     * @return Collection<int, AggregatedTimeSlot>
     */
    public function getAggregatedSlots(
        int $organizationId,
        Carbon $date,
        ?int $serviceId = null,
        ?int $locationId = null,
    ): Collection {
        $organization = Organization::findOrFail($organizationId);
        $professionalIds = $this->teamService->bookableMembers($organization, $serviceId)->pluck('id');
        $context = new AvailabilityContext($organizationId, $locationId);

        $slotsByProfessional = $professionalIds->mapWithKeys(
            fn (int $professionalId): array => [
                $professionalId => $this->availabilityService->getAvailableSlots($professionalId, $date, $serviceId, $context),
            ]
        );

        return $this->deduplicateAcrossProfessionals($slotsByProfessional, $date);
    }

    /**
     * @param  Collection<int, Collection<int, TimeSlot>>  $slotsByProfessional
     * @return Collection<int, AggregatedTimeSlot>
     */
    private function deduplicateAcrossProfessionals(Collection $slotsByProfessional, Carbon $date): Collection
    {
        $appointmentCounts = $this->appointmentCounts($slotsByProfessional->keys()->all(), $date);
        $bestPerStartTime = collect();

        foreach ($slotsByProfessional as $professionalId => $slots) {
            foreach ($slots as $slot) {
                $this->considerCandidate($bestPerStartTime, $appointmentCounts, $professionalId, $slot);
            }
        }

        return $bestPerStartTime->values()->sortBy(
            fn (AggregatedTimeSlot $slot): int => $slot->startTime->timestamp
        )->values();
    }

    private function considerCandidate(
        Collection $bestPerStartTime,
        Collection $appointmentCounts,
        int $professionalId,
        TimeSlot $slot,
    ): void {
        $key = $slot->startTime->toIso8601String();
        $current = $bestPerStartTime->get($key);
        $candidateLoad = $appointmentCounts->get($professionalId, 0);

        if ($current !== null && $appointmentCounts->get($current->professionalId, 0) <= $candidateLoad) {
            return;
        }

        $bestPerStartTime->put($key, new AggregatedTimeSlot($slot->startTime, $slot->endTime, true, $professionalId));
    }

    /**
     * @param  list<int>  $professionalIds
     * @return Collection<int, int>
     */
    private function appointmentCounts(array $professionalIds, Carbon $date): Collection
    {
        if ($professionalIds === []) {
            return collect();
        }

        $startOfDay = $date->copy()->startOfDay();

        return Appointment::whereIn('professional_id', $professionalIds)
            ->where('appointment_date', '>=', $startOfDay)
            ->where('appointment_date', '<', $startOfDay->copy()->addDay())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get()
            ->countBy('professional_id');
    }
}
