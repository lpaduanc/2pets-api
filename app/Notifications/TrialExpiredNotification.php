<?php

namespace App\Notifications;

use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialExpiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $planName
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Periodo de teste expirado - 2pets')
            ->view('emails.trial-expired', [
                'planName' => $this->planName,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'trial_expired',
            'title' => 'Trial expirado',
            'message' => "Seu periodo de teste do plano {$this->planName} expirou. Assine para continuar.",
            'action_url' => FrontendRoute::TUTOR_SUBSCRIPTION,
        ];
    }
}
