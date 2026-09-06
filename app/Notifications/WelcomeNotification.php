<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $userName
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Bem-vindo ao 2pets!')
            ->view('emails.welcome', [
                'userName' => $this->userName,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'welcome',
            'title' => 'Bem-vindo ao 2pets!',
            'message' => "Ola {$this->userName}, sua conta foi criada com sucesso!",
            'action_url' => '/tutor/pets/add',
        ];
    }
}
