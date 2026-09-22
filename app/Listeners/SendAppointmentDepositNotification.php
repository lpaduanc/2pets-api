<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\AppointmentDepositCharged;
use App\Events\AppointmentDepositPaid;
use App\Models\Appointment;
use App\Notifications\InAppNotification;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6 do fluxo de agendamento: tutor avisado da cobrança do sinal (com o valor e o
 * caminho para pagar), profissional avisado quando o webhook confirma o pagamento.
 * Separado de `SendAppointmentNotification` (ciclo de vida do agendamento em si) porque é
 * outra responsabilidade — pagamento, não status.
 */
class SendAppointmentDepositNotification implements ShouldQueue
{
    public function handleCharged(AppointmentDepositCharged $event): void
    {
        $appointment = $event->appointment->load(['client', 'service']);
        $client = $appointment->client;

        if ($client === null) {
            return;
        }

        $ticketUrl = $event->payment?->metadata['ticket_url'] ?? null;

        $this->safelyNotify(fn () => $client->notify(new InAppNotification(
            type: NotificationType::APPOINTMENT_DEPOSIT_REQUESTED,
            title: 'Sinal do agendamento',
            body: "Confirme seu agendamento de {$this->serviceLabel($appointment)} pagando o sinal de {$this->formattedAmount($appointment)}.",
            data: [
                'appointment_id' => $appointment->id,
                'deposit_amount' => (float) $appointment->deposit_amount,
                'ticket_url' => $ticketUrl,
            ],
            actionUrl: FrontendRoute::TUTOR_APPOINTMENTS,
        )), 'deposit_charged');
    }

    public function handlePaid(AppointmentDepositPaid $event): void
    {
        $appointment = $event->appointment->load(['professional', 'client', 'service']);
        $professional = $appointment->professional;

        if ($professional === null) {
            return;
        }

        $this->safelyNotify(fn () => $professional->notify(new InAppNotification(
            type: NotificationType::APPOINTMENT_DEPOSIT_PAID,
            title: 'Sinal recebido',
            body: "{$appointment->client?->name} pagou o sinal de {$this->formattedAmount($appointment)} do agendamento de {$this->serviceLabel($appointment)}.",
            data: ['appointment_id' => $appointment->id],
            actionUrl: FrontendRoute::PROFESSIONAL_APPOINTMENTS,
        )), 'deposit_paid');
    }

    public function subscribe($events): array
    {
        return [
            AppointmentDepositCharged::class => 'handleCharged',
            AppointmentDepositPaid::class => 'handlePaid',
        ];
    }

    private function serviceLabel(Appointment $appointment): string
    {
        return $appointment->service?->name ?? 'uma consulta';
    }

    private function formattedAmount(Appointment $appointment): string
    {
        return 'R$ '.number_format((float) $appointment->deposit_amount, 2, ',', '.');
    }

    private function safelyNotify(callable $notify, string $eventName): void
    {
        try {
            $notify();
        } catch (\Exception $e) {
            Log::error("Failed to send appointment {$eventName} notification", ['error' => $e->getMessage()]);
        }
    }
}
