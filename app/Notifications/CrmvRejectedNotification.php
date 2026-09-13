<?php

namespace App\Notifications;

use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrmvRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $professionalName,
        private readonly string $crmvNumber,
        private readonly string $rejectionReason
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verificacao de CRMV - 2pets')
            ->view('emails.crmv-rejected', [
                'professionalName' => $this->professionalName,
                'crmvNumber' => $this->crmvNumber,
                'rejectionReason' => $this->rejectionReason,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'crmv_rejected',
            'title' => 'CRMV nao verificado',
            'message' => "Motivo: {$this->rejectionReason}. Reenvie o documento.",
            'action_url' => FrontendRoute::PROFESSIONAL_PROFILE,
        ];
    }
}
