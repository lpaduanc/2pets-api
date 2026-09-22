<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\OrganizationMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * `GET professional/agenda/queue` — item 21 do backlog gap-simplesvet. "Próximos"/"Atendidos"
 * do dia, derivado de `Appointment::queueLabel()` (par `status`/`checked_in_at`), sem um
 * `queue_status` paralelo. Mesmo escopo de recurso de `DayAgendaService`: com organização
 * ativa, todos os profissionais bookáveis da equipe; sem organização, só a própria conta.
 */
final class AppointmentQueueService
{
    private const ATTENDED_LABELS = ['done', 'no_show'];

    /**
     * @return array{next: Collection<int, Appointment>, attended: Collection<int, Appointment>}
     */
    public function forDate(User $requestingUser, Carbon $date): array
    {
        $organizationId = $requestingUser->activeOrganizationId();
        $professionalIds = $organizationId !== null
            ? $this->organizationMemberUserIds($organizationId)
            : collect([$requestingUser->id]);

        $appointments = $this->appointmentsForDate($professionalIds, $date);

        return [
            'next' => $appointments->reject($this->isAttended(...))->values(),
            'attended' => $appointments->filter($this->isAttended(...))->values(),
        ];
    }

    private function isAttended(Appointment $appointment): bool
    {
        return in_array($appointment->queueLabel(), self::ATTENDED_LABELS, true);
    }

    /**
     * @return Collection<int, int>
     */
    private function organizationMemberUserIds(int $organizationId): Collection
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->pluck('user_id');
    }

    /**
     * @param  Collection<int, int>  $professionalIds
     * @return Collection<int, Appointment>
     */
    private function appointmentsForDate(Collection $professionalIds, Carbon $date): Collection
    {
        $startOfDay = $date->copy()->startOfDay();
        $startOfNextDay = $startOfDay->copy()->addDay();

        return Appointment::query()
            ->whereIn('professional_id', $professionalIds)
            ->where('appointment_date', '>=', $startOfDay)
            ->where('appointment_date', '<', $startOfNextDay)
            ->with(['pet', 'client', 'professional', 'service', 'appointmentType'])
            ->orderBy('appointment_time')
            ->get();
    }
}
