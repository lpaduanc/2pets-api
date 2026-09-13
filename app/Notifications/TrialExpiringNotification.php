<?php

namespace App\Notifications;

use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $planName,
        private readonly string $trialEndDate
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu periodo de teste expira em breve - 2pets')
            ->view('emails.trial-expiring', [
                'planName' => $this->planName,
                'trialEndDate' => $this->trialEndDate,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'trial_expiring',
            'title' => 'Trial expirando!',
            'message' => "Seu periodo de teste do plano {$this->planName} expira em 2 dias.",
            'action_url' => FrontendRoute::TUTOR_SUBSCRIPTION,
        ];
    }
}
