<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InAppNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly NotificationType $type,
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            // Every other Notification class in the app writes the payload under `message`
            // (see `NotificationResource`) — this used to write `body`, which the Resource
            // never read, so `SendAppointmentNotification`/`SendReviewNotification` reached
            // the API with an empty message.
            'message' => $this->body,
            'data' => $this->data,
        ];
    }
}
