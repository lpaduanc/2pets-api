<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class NotificationService
{
    public function __construct(
        private readonly PushNotificationService $pushService,
        private readonly WhatsAppService $whatsAppService,
        private readonly SmsService $smsService
    ) {}

    public function sendNotification(
        User $user,
        NotificationType $type,
        string $title,
        string $body,
        array $data = []
    ): void {
        $channels = $this->getEnabledChannels($user, $type);

        foreach ($channels as $channel) {
            try {
                $this->sendToChannel($channel, $user, $title, $body, $data);
            } catch (\Exception $e) {
                Log::error("Failed to send notification via {$channel->value}", [
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->createInAppNotification($user, $type, $title, $body, $data);
    }

    private function getEnabledChannels(User $user, NotificationType $type): array
    {
        $preferences = NotificationPreference::where('user_id', $user->id)
            ->where('notification_type', $type->value)
            ->where('enabled', true)
            ->get();

        if ($preferences->isEmpty()) {
            return $this->getDefaultChannels($type);
        }

        return $preferences->map(fn($pref) => NotificationChannel::from($pref->channel))->toArray();
    }

    private function getDefaultChannels(NotificationType $type): array
    {
        return match ($type) {
            NotificationType::APPOINTMENT_REMINDER_24H,
            NotificationType::APPOINTMENT_REMINDER_2H => [
                NotificationChannel::PUSH,
                NotificationChannel::EMAIL,
            ],
            NotificationType::APPOINTMENT_CONFIRMED,
            NotificationType::APPOINTMENT_CANCELLED,
            NotificationType::APPOINTMENT_RESCHEDULED => [
                NotificationChannel::PUSH,
                NotificationChannel::EMAIL,
            ],
            NotificationType::PAYMENT_RECEIVED,
            NotificationType::PAYMENT_FAILED => [
                NotificationChannel::EMAIL,
                NotificationChannel::PUSH,
            ],
            NotificationType::NEW_MESSAGE => [
                NotificationChannel::PUSH,
            ],
            default => [NotificationChannel::EMAIL],
        };
    }

    private function sendToChannel(
        NotificationChannel $channel,
        User $user,
        string $title,
        string $body,
        array $data
    ): void {
        match ($channel) {
            NotificationChannel::PUSH => $this->pushService->send($user, $title, $body, $data),
            NotificationChannel::WHATSAPP => $this->whatsAppService->send($user, $body),
            NotificationChannel::SMS => $this->smsService->send($user, $body),
            NotificationChannel::EMAIL => $this->sendEmail($user, $title, $body, $data),
        };
    }

    private function sendEmail(User $user, string $title, string $body, array $data): void
    {
        // Using Laravel's built-in Mail system
        // Will be implemented with Mailable classes
        Log::info("Email sent to {$user->email}", ['title' => $title]);
    }

    private function createInAppNotification(
        User $user,
        NotificationType $type,
        string $title,
        string $body,
        array $data
    ): void {
        $user->notify(new \App\Notifications\InAppNotification(
            $type,
            $title,
            $body,
            $data
        ));
    }
}

