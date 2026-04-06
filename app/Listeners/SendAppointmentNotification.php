<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\AppointmentBooked;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentRescheduled;
use App\Notifications\InAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendAppointmentNotification implements ShouldQueue
{
    public function handleBooked(AppointmentBooked $event): void
    {
        $appointment = $event->appointment->load(['client', 'professional', 'service']);

        // Notify the professional about new booking
        try {
            $professional = $appointment->professional;
            if ($professional) {
                $professional->notify(new InAppNotification(
                    type: NotificationType::APPOINTMENT_CONFIRMED,
                    title: 'Novo agendamento',
                    body: "Novo agendamento de {$appointment->client->name} para {$appointment->appointment_date->format('d/m/Y H:i')}",
                    data: [
                        'appointment_id' => $appointment->id,
                        'client_name' => $appointment->client->name,
                        'service' => $appointment->service?->name,
                    ]
                ));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send appointment booked notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleConfirmed(AppointmentConfirmed $event): void
    {
        $appointment = $event->appointment->load(['client', 'professional']);

        // Notify the client that appointment is confirmed
        try {
            $client = $appointment->client;
            if ($client) {
                $client->notify(new InAppNotification(
                    type: NotificationType::APPOINTMENT_CONFIRMED,
                    title: 'Consulta confirmada',
                    body: "Sua consulta em {$appointment->appointment_date->format('d/m/Y H:i')} foi confirmada!",
                    data: ['appointment_id' => $appointment->id]
                ));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send appointment confirmed notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleCancelled(AppointmentCancelled $event): void
    {
        $appointment = $event->appointment->load(['client', 'professional']);

        // Notify both parties
        try {
            $client = $appointment->client;
            $professional = $appointment->professional;

            if ($client) {
                $client->notify(new InAppNotification(
                    type: NotificationType::APPOINTMENT_CANCELLED,
                    title: 'Consulta cancelada',
                    body: "Sua consulta em {$appointment->appointment_date->format('d/m/Y H:i')} foi cancelada. Motivo: {$event->reason}",
                    data: ['appointment_id' => $appointment->id]
                ));
            }

            if ($professional) {
                $professional->notify(new InAppNotification(
                    type: NotificationType::APPOINTMENT_CANCELLED,
                    title: 'Agendamento cancelado',
                    body: "O agendamento de {$appointment->client->name} foi cancelado.",
                    data: ['appointment_id' => $appointment->id]
                ));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send appointment cancelled notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        $appointment = $event->appointment->load(['client', 'professional']);

        try {
            $client = $appointment->client;
            $professional = $appointment->professional;

            $notification = new InAppNotification(
                type: NotificationType::APPOINTMENT_RESCHEDULED,
                title: 'Consulta reagendada',
                body: "Consulta reagendada para {$appointment->appointment_date->format('d/m/Y H:i')}",
                data: [
                    'appointment_id' => $appointment->id,
                    'previous_date' => $event->previousDate,
                ]
            );

            if ($client) {
                $client->notify($notification);
            }
            if ($professional) {
                $professional->notify($notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send appointment rescheduled notification', ['error' => $e->getMessage()]);
        }
    }

    public function subscribe($events): array
    {
        return [
            AppointmentBooked::class => 'handleBooked',
            AppointmentConfirmed::class => 'handleConfirmed',
            AppointmentCancelled::class => 'handleCancelled',
            AppointmentRescheduled::class => 'handleRescheduled',
        ];
    }
}
