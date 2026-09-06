<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly float $amount,
        private readonly string $description
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Falha no pagamento - 2pets')
            ->view('emails.payment-failed', [
                'amount' => $this->amount,
                'description' => $this->description,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'title' => 'Falha no pagamento',
            'message' => 'Nao foi possivel processar o pagamento de R$ '.number_format($this->amount, 2, ',', '.').'.',
            'action_url' => '/tutor/wallet',
        ];
    }
}
