<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly float $amount,
        private readonly string $description,
        private readonly string $transactionId
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pagamento confirmado - 2pets')
            ->view('emails.payment-confirmed', [
                'amount' => $this->amount,
                'description' => $this->description,
                'transactionId' => $this->transactionId,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_confirmed',
            'title' => 'Pagamento confirmado!',
            'message' => 'Pagamento de R$ '.number_format($this->amount, 2, ',', '.').' confirmado.',
            'action_url' => '/tutor/appointments',
        ];
    }
}
