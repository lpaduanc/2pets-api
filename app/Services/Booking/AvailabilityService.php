<?php

namespace App\Services\Booking;

use App\DataTransferObjects\Booking\AvailabilityContext;
use App\DataTransferObjects\TimeSlot;
use App\Models\Appointment;
use App\Models\Availability;
use App\Models\BlockedTime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AvailabilityService
{
    public function __construct(private readonly StaffTimeOffChecker $staffTimeOffChecker) {}

    /**
     * `$context` filtra a agenda lida para o local/organização informados (Fase 2 do
     * fluxo de agendamento) — `null`/vazio preserva o comportamento anterior: qualquer
     * janela ativa do profissional, sem filtro de estabelecimento.
     */
    public function getAvailableSlots(
        int $professionalId,
        Carbon $date,
        ?int $serviceId = null,
        ?AvailabilityContext $context = null
    ): Collection {
        $professional = User::findOrFail($professionalId);
        $context ??= AvailabilityContext::none();
        $serviceDuration = $this->getServiceDuration($serviceId);

        if ($this->staffTimeOffChecker->isOnApprovedTimeOff($professionalId, $context->organizationId, $date)) {
            return collect();
        }

        $windows = $this->getAvailabilityWindowsForDay($professionalId, $date->dayOfWeek, $context);

        if ($windows->isEmpty()) {
            return collect();
        }

        $slots = $windows->flatMap(fn (Availability $window): Collection => $this->generateTimeSlots(
            $date,
            $window->start_time,
            $window->end_time,
            $window->slot_duration,
            $window->buffer_time
        ));

        return $this->filterAvailableSlots($professionalId, $date, $slots, $serviceDuration, $context);
    }

    /**
     * Bug achado pelo frontend (Fase 4): esta consulta usava `->first()`, então um
     * profissional com DUAS janelas no mesmo dia (ex.: manhã 09:00–12:00 e tarde
     * 14:00–16:00, o caso mais comum de clínica com intervalo de almoço) tinha a segunda
     * janela IGNORADA em silêncio — a escrita (Fase 1, `AvailabilityManagementService`) já
     * aceitava e validava N janelas por dia; só esta leitura pública nunca tinha
     * acompanhado. `orderBy('start_time')` garante que os slots saiam em ordem
     * cronológica quando `getAvailableSlots()` concatena as janelas.
     *
     * @return Collection<int, Availability>
     */
    private function getAvailabilityWindowsForDay(int $professionalId, int $dayOfWeek, AvailabilityContext $context): Collection
    {
        return Availability::where('professional_id', $professionalId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->when($context->locationId !== null, fn ($query) => $query->where('location_id', $context->locationId))
            ->when(
                $context->locationId === null && $context->organizationId !== null,
                fn ($query) => $query->where('organization_id', $context->organizationId)
            )
            ->orderBy('start_time')
            ->get();
    }

    private function getServiceDuration(?int $serviceId): int
    {
        if (! $serviceId) {
            return 30; // default duration
        }

        $service = \App\Models\Service::find($serviceId);

        return $service ? $service->duration : 30;
    }

    private function generateTimeSlots(
        Carbon $date,
        string $startTime,
        string $endTime,
        int $slotDuration,
        int $bufferTime
    ): Collection {
        $slots = collect();

        $start = Carbon::parse($date->format('Y-m-d').' '.$startTime);
        $end = Carbon::parse($date->format('Y-m-d').' '.$endTime);

        $totalMinutes = $slotDuration + $bufferTime;

        $current = $start->copy();

        while ($current->lessThan($end)) {
            $slotEnd = $current->copy()->addMinutes($slotDuration);

            if ($slotEnd->lessThanOrEqualTo($end)) {
                $slots->push(new TimeSlot(
                    startTime: $current->copy(),
                    endTime: $slotEnd->copy(),
                    isAvailable: true
                ));
            }

            $current->addMinutes($totalMinutes);
        }

        return $slots;
    }

    private function filterAvailableSlots(
        int $professionalId,
        Carbon $date,
        Collection $slots,
        int $serviceDuration,
        AvailabilityContext $context
    ): Collection {
        $existingAppointments = $this->getExistingAppointments($professionalId, $date);
        $blockedTimes = $this->getBlockedTimes($professionalId, $date, $context);

        return $slots->map(function (TimeSlot $slot) use ($existingAppointments, $blockedTimes, $serviceDuration) {
            $slotEnd = $slot->startTime->copy()->addMinutes($serviceDuration);

            $isBlocked = $this->isTimeBlocked($slot->startTime, $slotEnd, $blockedTimes);
            $hasAppointment = $this->hasOverlappingAppointment($slot->startTime, $slotEnd, $existingAppointments);

            return new TimeSlot(
                startTime: $slot->startTime,
                endTime: $slot->endTime,
                isAvailable: ! $isBlocked && ! $hasAppointment
            );
        })->filter(fn (TimeSlot $slot) => $slot->isAvailable);
    }

    /**
     * `whereDate()` casts `appointment_date` (a datetime column), which blocks
     * index usage — a half-open range over the same calendar day is sargable
     * and returns the same rows.
     */
    private function getExistingAppointments(int $professionalId, Carbon $date): Collection
    {
        $startOfDay = $date->copy()->startOfDay();

        return Appointment::where('professional_id', $professionalId)
            ->where('appointment_date', '>=', $startOfDay)
            ->where('appointment_date', '<', $startOfDay->copy()->addDay())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get();
    }

    /**
     * Same sargability fix as above: "blocked time whose calendar-date range
     * covers $date" becomes a half-open range against `start_datetime`/`end_datetime`.
     *
     * Item 21 do backlog gap-simplesvet — corrige o bug de bloqueio amplo: um
     * `BlockedTime` com `professional_id = null` e `organization_id`/`location_id`
     * preenchido (bloqueio da empresa/local inteiro) precisa esconder o slot de
     * QUALQUER profissional daquele escopo, não só de quem tem `professional_id`
     * batendo — antes desta correção essa cláusula nunca era lida.
     */
    private function getBlockedTimes(int $professionalId, Carbon $date, AvailabilityContext $context): Collection
    {
        $startOfDay = $date->copy()->startOfDay();

        return BlockedTime::where('start_datetime', '<', $startOfDay->copy()->addDay())
            ->where('end_datetime', '>=', $startOfDay)
            ->where(fn (Builder $query) => $this->scopeToProfessionalOrBroadBlock($query, $professionalId, $context))
            ->get();
    }

    /**
     * @param  Builder<BlockedTime>  $query
     * @return Builder<BlockedTime>
     */
    private function scopeToProfessionalOrBroadBlock(Builder $query, int $professionalId, AvailabilityContext $context): Builder
    {
        $query->where('professional_id', $professionalId);

        $broadScope = $this->broadBlockScope($context);
        if ($broadScope !== null) {
            [$column, $value] = $broadScope;
            $query->orWhere(fn (Builder $broad) => $broad->whereNull('professional_id')->where($column, $value));
        }

        return $query;
    }

    /**
     * Bloqueio amplo é resolvido pelo local quando informado (mais específico que a
     * organização); sem contexto nenhum (agenda "solta" pré-Fase 2), não há como saber a
     * organização/local do profissional aqui — preserva o comportamento anterior.
     *
     * @return array{0: string, 1: int}|null
     */
    private function broadBlockScope(AvailabilityContext $context): ?array
    {
        if ($context->locationId !== null) {
            return ['location_id', $context->locationId];
        }

        if ($context->organizationId !== null) {
            return ['organization_id', $context->organizationId];
        }

        return null;
    }

    private function isTimeBlocked(Carbon $start, Carbon $end, Collection $blockedTimes): bool
    {
        return $blockedTimes->contains(function (BlockedTime $blocked) use ($start, $end) {
            return $start->lessThan($blocked->end_datetime) && $end->greaterThan($blocked->start_datetime);
        });
    }

    private function hasOverlappingAppointment(Carbon $start, Carbon $end, Collection $appointments): bool
    {
        return $appointments->contains(function (Appointment $appointment) use ($start, $end) {
            $appointmentStart = Carbon::parse($appointment->appointment_date);
            $appointmentEnd = $appointmentStart->copy()->addMinutes($appointment->duration ?? 30);

            return $start->lessThan($appointmentEnd) && $end->greaterThan($appointmentStart);
        });
    }
}
