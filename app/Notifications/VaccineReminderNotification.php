<?php

namespace App\Notifications;

use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VaccineReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $petName,
        private readonly string $vaccineName,
        private readonly int $daysUntil,
        private readonly string $dueDate
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Vacina de {$this->petName} vence em {$this->daysUntil} dias - 2pets")
            ->view('emails.vaccine-reminder', [
                'petName' => $this->petName,
                'vaccineName' => $this->vaccineName,
                'daysUntil' => $this->daysUntil,
                'dueDate' => $this->dueDate,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'vaccine_reminder',
            'title' => "Vacina de {$this->petName} vencendo!",
            'message' => "A vacina {$this->vaccineName} vence em {$this->daysUntil} dias ({$this->dueDate}).",
            'action_url' => FrontendRoute::TUTOR_HEALTH,
        ];
    }
}
