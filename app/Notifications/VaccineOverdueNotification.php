<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VaccineOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $petName,
        private readonly string $vaccineName,
        private readonly string $dueDate
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("URGENTE: Vacina de {$this->petName} vencida! - 2pets")
            ->view('emails.vaccine-overdue', [
                'petName' => $this->petName,
                'vaccineName' => $this->vaccineName,
                'dueDate' => $this->dueDate,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'vaccine_overdue',
            'title' => "URGENTE: Vacina de {$this->petName} vencida!",
            'message' => "A vacina {$this->vaccineName} esta vencida desde {$this->dueDate}.",
            'action_url' => '/tutor/health',
        ];
    }
}
