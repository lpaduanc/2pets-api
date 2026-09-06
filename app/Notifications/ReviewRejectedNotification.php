<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $professionalName,
        private readonly string $rejectionReason
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Avaliacao nao aprovada - 2pets')
            ->view('emails.review-rejected', [
                'professionalName' => $this->professionalName,
                'rejectionReason' => $this->rejectionReason,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'review_rejected',
            'title' => 'Avaliacao nao aprovada',
            'message' => "Sua avaliacao para {$this->professionalName} nao foi aprovada.",
            'action_url' => '/tutor/dashboard',
        ];
    }
}
