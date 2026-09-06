<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewPublishedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $tutorName,
        private readonly int $rating,
        private readonly string $comment,
        private readonly string $professionalProfileUrl
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Nova avaliacao: {$this->rating} estrelas - 2pets")
            ->view('emails.review-published', [
                'tutorName' => $this->tutorName,
                'rating' => $this->rating,
                'comment' => $this->comment,
                'professionalProfileUrl' => $this->professionalProfileUrl,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'review_published',
            'title' => 'Nova avaliacao recebida!',
            'message' => "{$this->tutorName} avaliou voce com {$this->rating} estrelas.",
            'action_url' => $this->professionalProfileUrl,
        ];
    }
}
