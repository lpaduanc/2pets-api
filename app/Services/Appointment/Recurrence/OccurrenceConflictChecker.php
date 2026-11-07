<?php

namespace App\Services\Appointment\Recurrence;

use App\Models\Appointment;
use App\Models\Availability;
use App\Models\BlockedTime;
use App\Models\Holiday;
use App\Services\Booking\StaffTimeOffChecker;
use Carbon\CarbonImmutable;

/**
 * Conflitos de UMA ocorrência (PLANO 3.1), na ordem em que são relatados (o 1º que bater):
 *  - `holiday`: feriado do catálogo (global ou da organização), ativo;
 *  - `blocked`: bloqueio de agenda do profissional (ou bloqueio amplo da organização) que cruza o
 *    horário, ou folga aprovada (`StaffTimeOffChecker`);
 *  - `availability`: o profissional tem grade de horários cadastrada e o horário não cabe em
 *    nenhuma janela ativa daquele dia da semana (sem grade nenhuma, não há o que conferir);
 *  - `overlap`: outro agendamento do profissional (não cancelado nem "não compareceu") cruza o
 *    horário.
 * Horários são de parede (São Paulo), como a agenda grava: data em `appointment_date` e hora em
 * `appointment_time`.
 */
final class OccurrenceConflictChecker
{
    public function __construct(private readonly StaffTimeOffChecker $timeOff) {}

    /**
     * @param  list<int>  $ignoreAppointmentIds
     * @return array{reason: string, detail: string}|null
     */
    public function check(int $professionalId, ?int $organizationId, CarbonImmutable $start, int $duration, array $ignoreAppointmentIds = []): ?array
    {
        $end = $start->addMinutes($duration);

        $holiday = Holiday::query()->visibleTo($organizationId, $professionalId)->where('active', true)->get(['name', 'date', 'recurring_annually'])
            ->first(fn (Holiday $h): bool => $h->coversDate($start));
        if ($holiday !== null) {
            return ['reason' => 'holiday', 'detail' => "Feriado: {$holiday->name}"];
        }

        if ($this->isBlocked($professionalId, $organizationId, $start, $end)) {
            return ['reason' => 'blocked', 'detail' => 'Horário bloqueado na agenda do profissional.'];
        }

        if ($this->timeOff->isOnApprovedTimeOff($professionalId, $organizationId, $start)) {
            return ['reason' => 'blocked', 'detail' => 'Profissional de folga nesse dia.'];
        }

        if (! $this->fitsAvailability($professionalId, $organizationId, $start, $end)) {
            return ['reason' => 'availability', 'detail' => 'Fora do horário de atendimento do profissional.'];
        }

        $other = $this->overlapping($professionalId, $start, $end, $ignoreAppointmentIds);

        return $other === null ? null : ['reason' => 'overlap', 'detail' => sprintf('Conflita com outro agendamento às %s.', $other->appointment_time?->format('H:i'))];
    }

    private function isBlocked(int $professionalId, ?int $organizationId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return BlockedTime::query()
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->where(fn ($q) => $q->where('professional_id', $professionalId)
                ->when($organizationId !== null, fn ($broad) => $broad->orWhere(fn ($b) => $b->whereNull('professional_id')->where('organization_id', $organizationId))))
            ->exists();
    }

    private function fitsAvailability(int $professionalId, ?int $organizationId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $windows = Availability::query()->where('professional_id', $professionalId)->where('is_active', true)
            ->when($organizationId !== null, fn ($q) => $q->where(fn ($o) => $o->where('organization_id', $organizationId)->orWhereNull('organization_id')))
            ->get(['day_of_week', 'start_time', 'end_time']);

        if ($windows->isEmpty()) {
            return true;
        }

        $from = $start->format('H:i:s');
        $to = $end->isSameDay($start) ? $end->format('H:i:s') : '24:00:00';

        return $windows->contains(fn (Availability $w): bool => (int) $w->day_of_week === $start->dayOfWeek
            && substr((string) $w->start_time, 0, 8) <= $from && substr((string) $w->end_time, 0, 8) >= $to);
    }

    /** @param  list<int>  $ignore */
    private function overlapping(int $professionalId, CarbonImmutable $start, CarbonImmutable $end, array $ignore): ?Appointment
    {
        return Appointment::query()
            ->where('professional_id', $professionalId)
            ->where('appointment_date', '>=', $start->startOfDay())
            ->where('appointment_date', '<', $start->startOfDay()->addDay())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($ignore !== [], fn ($q) => $q->whereNotIn('id', $ignore))
            ->get(['id', 'appointment_date', 'appointment_time', 'duration'])
            ->first(function (Appointment $a) use ($start, $end): bool {
                $otherStart = CarbonImmutable::parse($start->toDateString().' '.($a->appointment_time?->format('H:i') ?? '00:00'));

                return $start->lt($otherStart->addMinutes($a->duration ?? 30)) && $end->gt($otherStart);
            });
    }
}
