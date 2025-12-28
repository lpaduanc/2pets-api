<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Appointment;
use App\Models\ScheduledNotification;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendScheduledNotifications extends Command
{
    protected $signature = 'notifications:send';
    protected $description = 'Send scheduled notifications';

    public function __construct(
        private readonly NotificationService $notificationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Processing scheduled notifications...');

        $this->sendAppointmentReminders();
        $this->sendQueuedNotifications();

        $this->info('Scheduled notifications processed.');

        return Command::SUCCESS;
    }

    private function sendAppointmentReminders(): void
    {
        // 24-hour reminders
        $appointments24h = Appointment::where('status', 'scheduled')
            ->whereBetween('appointment_date', [
                now()->addHours(23)->addMinutes(50),
                now()->addHours(24)->addMinutes(10),
            ])
            ->whereDoesntHave('client.notifications', function ($query) {
                $query->where('type', NotificationType::APPOINTMENT_REMINDER_24H->value)
                      ->where('created_at', '>=', now()->subDay());
            })
            ->with(['client', 'professional', 'service'])
            ->get();

        foreach ($appointments24h as $appointment) {
            $this->sendAppointmentReminder($appointment, NotificationType::APPOINTMENT_REMINDER_24H, '24 horas');
        }

        // 2-hour reminders
        $appointments2h = Appointment::where('status', 'scheduled')
            ->whereBetween('appointment_date', [
                now()->addHours(1)->addMinutes(50),
                now()->addHours(2)->addMinutes(10),
            ])
            ->whereDoesntHave('client.notifications', function ($query) {
                $query->where('type', NotificationType::APPOINTMENT_REMINDER_2H->value)
                      ->where('created_at', '>=', now()->subHours(2));
            })
            ->with(['client', 'professional', 'service'])
            ->get();

        foreach ($appointments2h as $appointment) {
            $this->sendAppointmentReminder($appointment, NotificationType::APPOINTMENT_REMINDER_2H, '2 horas');
        }

        $this->info("Sent {$appointments24h->count()} 24h reminders and {$appointments2h->count()} 2h reminders");
    }

    private function sendAppointmentReminder(
        Appointment $appointment,
        NotificationType $type,
        string $timeframe
    ): void {
        $professionalName = $appointment->professional->professional->business_name 
            ?? $appointment->professional->name;

        $date = $appointment->appointment_date->format('d/m/Y');
        $time = $appointment->appointment_date->format('H:i');

        $title = "Lembrete: Consulta em {$timeframe}";
        $body = "Você tem uma consulta agendada com {$professionalName} em {$date} às {$time}.";

        $this->notificationService->sendNotification(
            $appointment->client,
            $type,
            $title,
            $body,
            [
                'appointment_id' => $appointment->id,
                'appointment_date' => $appointment->appointment_date->toISOString(),
                'professional_name' => $professionalName,
            ]
        );
    }

    private function sendQueuedNotifications(): void
    {
        $notifications = ScheduledNotification::where('scheduled_for', '<=', now())
            ->where('sent', false)
            ->with('user')
            ->get();

        foreach ($notifications as $notification) {
            try {
                $type = NotificationType::from($notification->notification_type);
                $data = $notification->data;

                $this->notificationService->sendNotification(
                    $notification->user,
                    $type,
                    $data['title'] ?? '',
                    $data['body'] ?? '',
                    $data['extra'] ?? []
                );

                $notification->update([
                    'sent' => true,
                    'sent_at' => now(),
                ]);
            } catch (\Exception $e) {
                $this->error("Failed to send notification {$notification->id}: {$e->getMessage()}");
            }
        }

        $this->info("Sent {$notifications->count()} queued notifications");
    }
}

