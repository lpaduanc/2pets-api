<?php

namespace App\Services\Dashboard\ActivityFeed;

use App\DataTransferObjects\TutorActivityItem;
use App\Enums\ActivityFeedTone;
use App\Enums\ActivityFeedType;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Feed de "o que aconteceu com meus agendamentos" — lido do `activity_log` do próprio
 * `Appointment` (trait adicionada junto com esta fonte; ver `Appointment::getActivitylogOptions()`),
 * nunca das colunas de timestamp isoladas: `rescheduleBooking()` reaproveita `status = pending`
 * ao reagendar, então só o diff (quais colunas mudaram) distingue "reagendado" de "voltou a
 * aguardar confirmação por outro motivo".
 *
 * Limite conhecido: como o log só passou a existir a partir desta mudança, agendamentos
 * criados/alterados ANTES dela não aparecem aqui — só o comportamento futuro é coberto.
 */
final class AppointmentActivitySource
{
    /**
     * @return list<TutorActivityItem>
     */
    public function fetch(User $tutor, int $limit): array
    {
        $appointmentIds = Appointment::where('client_id', $tutor->id)->pluck('id')->all();
        if ($appointmentIds === []) {
            return [];
        }

        $activities = $this->queryActivities($appointmentIds, $limit);
        $appointments = $this->appointmentContext($activities->pluck('subject_id')->unique()->all());

        return $activities
            ->map(fn (Activity $activity) => $this->toItem($activity, $appointments))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $appointmentIds
     */
    private function queryActivities(array $appointmentIds, int $limit): Collection
    {
        return Activity::query()
            ->where('subject_type', Appointment::class)
            ->whereIn('subject_id', $appointmentIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Appointment>
     */
    private function appointmentContext(array $ids): Collection
    {
        return Appointment::whereIn('id', $ids)
            ->with(['professional:id,name', 'service:id,name', 'pet:id,name'])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    private function toItem(Activity $activity, Collection $appointments): ?TutorActivityItem
    {
        $appointment = $appointments->get((int) $activity->subject_id);
        if ($appointment === null) {
            return null;
        }

        $attributes = $activity->properties?->toArray()['attributes'] ?? [];
        $meta = $this->resolveEvent($activity->event, $attributes);

        return new TutorActivityItem(
            id: "activity:{$activity->id}",
            type: ActivityFeedType::APPOINTMENT,
            icon: $meta['icon'],
            tone: $meta['tone'],
            title: $meta['title'],
            description: $this->description($appointment, $attributes),
            petName: $appointment->pet?->name,
            link: FrontendRoute::TUTOR_APPOINTMENTS,
            createdAt: $activity->created_at,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{title: string, icon: string, tone: ActivityFeedTone}
     */
    private function resolveEvent(?string $event, array $attributes): array
    {
        if ($event === 'created') {
            return ['title' => 'Consulta agendada', 'icon' => 'event_available', 'tone' => ActivityFeedTone::PRIMARY];
        }

        if (array_key_exists('appointment_date', $attributes) || array_key_exists('appointment_time', $attributes)) {
            return ['title' => 'Consulta reagendada', 'icon' => 'update', 'tone' => ActivityFeedTone::WARNING];
        }

        if (array_key_exists('status', $attributes)) {
            return $this->statusEvent((string) $attributes['status']);
        }

        return ['title' => 'Consulta atualizada', 'icon' => 'event', 'tone' => ActivityFeedTone::INFO];
    }

    /**
     * @return array{title: string, icon: string, tone: ActivityFeedTone}
     */
    private function statusEvent(string $status): array
    {
        return match ($status) {
            AppointmentStatus::CONFIRMED->value => ['title' => 'Consulta confirmada', 'icon' => 'event_available', 'tone' => ActivityFeedTone::SUCCESS],
            AppointmentStatus::CANCELLED->value => ['title' => 'Consulta cancelada', 'icon' => 'event_busy', 'tone' => ActivityFeedTone::ERROR],
            AppointmentStatus::COMPLETED->value => ['title' => 'Consulta concluída', 'icon' => 'check_circle', 'tone' => ActivityFeedTone::SUCCESS],
            AppointmentStatus::IN_PROGRESS->value => ['title' => 'Consulta em andamento', 'icon' => 'play_circle', 'tone' => ActivityFeedTone::INFO],
            AppointmentStatus::NO_SHOW->value => ['title' => 'Não comparecimento registrado', 'icon' => 'error', 'tone' => ActivityFeedTone::WARNING],
            default => ['title' => 'Consulta atualizada', 'icon' => 'event', 'tone' => ActivityFeedTone::INFO],
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function description(Appointment $appointment, array $attributes): ?string
    {
        if (! empty($attributes['cancellation_reason'])) {
            return "Motivo: {$attributes['cancellation_reason']}";
        }

        return match (true) {
            $appointment->service?->name !== null && $appointment->professional?->name !== null => "{$appointment->service->name} com {$appointment->professional->name}",
            $appointment->professional?->name !== null => "Com {$appointment->professional->name}",
            default => null,
        };
    }
}
