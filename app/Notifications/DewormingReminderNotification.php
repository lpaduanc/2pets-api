<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DewormingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $petName,
        private readonly string $dueDate
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Vermifugo de {$this->petName} proximo - 2pets")
            ->view('emails.deworming-reminder', [
                'petName' => $this->petName,
                'dueDate' => $this->dueDate,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'deworming_reminder',
            'title' => "Vermifugo de {$this->petName}",
            'message' => "O vermifugo esta proximo! Data prevista: {$this->dueDate}.",
            'action_url' => '/tutor/health',
        ];
    }
}
