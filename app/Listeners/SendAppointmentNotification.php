<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\AppointmentBooked;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentRejected;
use App\Events\AppointmentRescheduled;
use App\Models\Appointment;
use App\Notifications\InAppNotification;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4 do fluxo de agendamento: o profissional precisa saber QUAL serviço o tutor quer
 * (não só "novo agendamento"), e o tutor precisa ser avisado quando o profissional
 * confirma OU recusa — antes desta fase, só `AppointmentConfirmed` notificava o tutor;
 * recusa não existia como conceito próprio.
 */
class SendAppointmentNotification implements ShouldQueue
{
    private const APPOINTMENT_RELATIONS = ['client', 'professional', 'service', 'pet'];

    public function handleBooked(AppointmentBooked $event): void
    {
        $appointment = $event->appointment->load(self::APPOINTMENT_RELATIONS);
        $professional = $appointment->professional;

        if ($professional === null) {
            return;
        }

        $this->safelyNotify(fn () => $professional->notify(new InAppNotification(
            type: NotificationType::APPOINTMENT_REQUESTED,
            title: 'Novo pedido de agendamento',
            body: "{$appointment->client?->name} quer agendar {$this->serviceLabel($appointment)} para {$this->petLabel($appointment)} em {$this->formattedDate($appointment)}.",
            data: [
                'appointment_id' => $appointment->id,
                'client_name' => $appointment->client?->name,
                'pet_name' => $appointment->pet?->name,
                'service' => $appointment->service?->name,
            ],
            actionUrl: FrontendRoute::PROFESSIONAL_APPOINTMENTS,
        )), 'booked');
    }

    public function handleConfirmed(AppointmentConfirmed $event): void
    {
        $appointment = $event->appointment->load(self::APPOINTMENT_RELATIONS);
        $client = $appointment->client;

        if ($client === null) {
            return;
        }

        $this->safelyNotify(fn () => $client->notify(new InAppNotification(
            type: NotificationType::APPOINTMENT_CONFIRMED,
            title: 'Consulta confirmada',
            body: "{$this->serviceLabel($appointment)} para {$this->petLabel($appointment)} em {$this->formattedDate($appointment)} foi confirmada!",
            data: ['appointment_id' => $appointment->id],
            actionUrl: FrontendRoute::TUTOR_APPOINTMENTS,
        )), 'confirmed');
    }

    /**
     * Fase 4: o profissional pode RECUSAR um pedido de agendamento (endpoint próprio,
     * `AppointmentConfirmationController::reject`) — o tutor precisa saber, com o motivo
     * quando houver um.
     */
    public function handleRejected(AppointmentRejected $event): void
    {
        $appointment = $event->appointment->load(self::APPOINTMENT_RELATIONS);
        $client = $appointment->client;

        if ($client === null) {
            return;
        }

        $body = "Seu pedido de {$this->serviceLabel($appointment)} para {$this->petLabel($appointment)} em {$this->formattedDate($appointment)} foi recusado.";

        if ($event->reason !== null && $event->reason !== '') {
            $body .= " Motivo: {$event->reason}";
        }

        $this->safelyNotify(fn () => $client->notify(new InAppNotification(
            type: NotificationType::APPOINTMENT_REJECTED,
            title: 'Agendamento recusado',
            body: $body,
            data: ['appointment_id' => $appointment->id, 'reason' => $event->reason],
            actionUrl: FrontendRoute::TUTOR_APPOINTMENTS,
        )), 'rejected');
    }

    public function handleCancelled(AppointmentCancelled $event): void
    {
        $appointment = $event->appointment->load(self::APPOINTMENT_RELATIONS);

        $this->safelyNotify(function () use ($appointment, $event): void {
            $appointment->client?->notify(new InAppNotification(
                type: NotificationType::APPOINTMENT_CANCELLED,
                title: 'Consulta cancelada',
                body: "Sua consulta em {$this->formattedDate($appointment)} foi cancelada. Motivo: {$event->reason}",
                data: ['appointment_id' => $appointment->id],
                actionUrl: FrontendRoute::TUTOR_APPOINTMENTS,
            ));

            $appointment->professional?->notify(new InAppNotification(
                type: NotificationType::APPOINTMENT_CANCELLED,
                title: 'Agendamento cancelado',
                body: "O agendamento de {$appointment->client?->name} foi cancelado.",
                data: ['appointment_id' => $appointment->id],
                actionUrl: FrontendRoute::PROFESSIONAL_APPOINTMENTS,
            ));
        }, 'cancelled');
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        $appointment = $event->appointment->load(self::APPOINTMENT_RELATIONS);

        $this->safelyNotify(function () use ($appointment, $event): void {
            $data = ['appointment_id' => $appointment->id, 'previous_date' => $event->previousDate];
            $body = "Consulta reagendada para {$this->formattedDate($appointment)}";

            $appointment->client?->notify(new InAppNotification(
                NotificationType::APPOINTMENT_RESCHEDULED,
                'Consulta reagendada',
                $body,
                $data,
                FrontendRoute::TUTOR_APPOINTMENTS,
            ));

            $appointment->professional?->notify(new InAppNotification(
                NotificationType::APPOINTMENT_RESCHEDULED,
                'Consulta reagendada',
                $body,
                $data,
                FrontendRoute::PROFESSIONAL_APPOINTMENTS,
            ));
        }, 'rescheduled');
    }

    public function subscribe($events): array
    {
        return [
            AppointmentBooked::class => 'handleBooked',
            AppointmentConfirmed::class => 'handleConfirmed',
            AppointmentRejected::class => 'handleRejected',
            AppointmentCancelled::class => 'handleCancelled',
            AppointmentRescheduled::class => 'handleRescheduled',
        ];
    }

    private function serviceLabel(Appointment $appointment): string
    {
        return $appointment->service?->name ?? 'uma consulta';
    }

    private function petLabel(Appointment $appointment): string
    {
        return $appointment->pet?->name ?? 'o pet';
    }

    private function formattedDate(Appointment $appointment): string
    {
        return $appointment->appointment_date->format('d/m/Y H:i');
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
