<?php

namespace App\Notifications;

use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrmvApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $professionalName,
        private readonly string $crmvNumber,
        private readonly string $crmvState
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('CRMV Verificado com Sucesso! - 2pets')
            ->view('emails.crmv-approved', [
                'professionalName' => $this->professionalName,
                'crmvNumber' => $this->crmvNumber,
                'crmvState' => $this->crmvState,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'crmv_approved',
            'title' => 'CRMV Verificado!',
            'message' => "Seu CRMV {$this->crmvNumber}/{$this->crmvState} foi verificado com sucesso.",
            'action_url' => FrontendRoute::PROFESSIONAL_DASHBOARD,
        ];
    }
}
