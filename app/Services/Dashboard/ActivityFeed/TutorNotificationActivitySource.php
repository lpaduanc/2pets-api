<?php

namespace App\Services\Dashboard\ActivityFeed;

use App\DataTransferObjects\TutorActivityItem;
use App\Enums\ActivityFeedTone;
use App\Enums\ActivityFeedType;
use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Feed de notificações in-app que NÃO têm uma fonte mais rica em outro lugar. `pet_updated_by_vet`,
 * os tipos de agendamento (`appointment_*`) e `payment_confirmed`/`payment_failed` ficam de
 * fora de propósito — `TutorRecordActivitySource`, `AppointmentActivitySource` e
 * `PaymentActivitySource` já cobrem o mesmo evento com mais contexto (diff de campo, nome do
 * profissional, valor), e mostrar os dois duplicaria a linha do tempo.
 */
final class TutorNotificationActivitySource
{
    /**
     * @var list<string>
     */
    private const EXCLUDED_TYPES = [
        'pet_updated_by_vet',
        'payment_confirmed',
        'payment_failed',
        NotificationType::APPOINTMENT_REQUESTED->value,
        NotificationType::APPOINTMENT_CONFIRMED->value,
        NotificationType::APPOINTMENT_REJECTED->value,
        NotificationType::APPOINTMENT_CANCELLED->value,
        NotificationType::APPOINTMENT_RESCHEDULED->value,
        NotificationType::APPOINTMENT_DEPOSIT_REQUESTED->value,
        NotificationType::APPOINTMENT_DEPOSIT_PAID->value,
    ];

    /**
     * @return list<TutorActivityItem>
     */
    public function fetch(User $tutor, int $limit): array
    {
        $notifications = DB::table('notifications')
            ->where('notifiable_id', $tutor->id)
            ->where('notifiable_type', User::class)
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $notifications->map(fn (object $row) => $this->toItem($row))->all();
    }

    private function toItem(object $row): TutorActivityItem
    {
        $payload = json_decode($row->data, true) ?? [];
        $type = $payload['type'] ?? 'notification';

        return new TutorActivityItem(
            id: "notification:{$row->id}",
            type: ActivityFeedType::NOTIFICATION,
            icon: $this->icon($type),
            tone: $this->tone($type),
            title: $payload['title'] ?? 'Notificação',
            description: $payload['message'] ?? null,
            petName: $payload['data']['pet_name'] ?? null,
            link: $payload['action_url'] ?? null,
            createdAt: Carbon::parse($row->created_at),
        );
    }

    private function icon(string $type): string
    {
        return match (true) {
            str_contains($type, 'vaccin') => 'vaccines',
            str_contains($type, 'deworming') || str_contains($type, 'medication') => 'medication',
            str_contains($type, 'appointment_reminder') => 'schedule',
            str_contains($type, 'quote') => 'request_quote',
            str_contains($type, 'crmv') => 'badge',
            str_contains($type, 'trial') => 'timer',
            str_contains($type, 'vet_access') => 'verified_user',
            str_contains($type, 'review') => 'star',
            str_contains($type, 'lost_pet') => 'pets',
            $type === 'new_message' => 'chat',
            $type === 'waitlist_available' => 'event_available',
            $type === 'welcome' => 'celebration',
            default => 'notifications',
        };
    }

    private function tone(string $type): ActivityFeedTone
    {
        return match (true) {
            str_contains($type, 'failed') || str_contains($type, 'rejected') || str_contains($type, 'overdue') || str_contains($type, 'revoked') => ActivityFeedTone::ERROR,
            str_contains($type, 'reminder') || str_contains($type, 'due') || $type === 'waitlist_available' || str_contains($type, 'expiring') => ActivityFeedTone::WARNING,
            str_contains($type, 'approved') || str_contains($type, 'published') || $type === 'welcome' => ActivityFeedTone::SUCCESS,
            default => ActivityFeedTone::INFO,
        };
    }
}
