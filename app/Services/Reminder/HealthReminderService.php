<?php

namespace App\Services\Reminder;

use App\Enums\NotificationType;
use App\Models\Medication;
use App\Models\Pet;
use App\Models\Reminder;
use App\Models\ReminderPreference;
use App\Models\Vaccination;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;

final class HealthReminderService
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    public function createVaccinationReminder(Vaccination $vaccination): ?Reminder
    {
        if (!$vaccination->next_dose_date) {
            return null;
        }

        $pet = $vaccination->pet;
        $user = $pet->user;

        $preference = $this->getUserPreference($user->id, 'vaccination');
        $reminderDate = $vaccination->next_dose_date->subDays($preference->days_before);

        if ($reminderDate->isPast()) {
            return null;
        }

        return Reminder::create([
            'user_id' => $user->id,
            'pet_id' => $pet->id,
            'type' => 'vaccination',
            'title' => "Vacina: {$vaccination->vaccine_name}",
            'description' => "A próxima dose de {$vaccination->vaccine_name} para {$pet->name} está próxima.",
            'due_date' => $vaccination->next_dose_date,
            'reminder_date' => $reminderDate,
            'metadata' => ['vaccination_id' => $vaccination->id],
        ]);
    }

    public function createMedicationReminder(Medication $medication): Collection
    {
        $pet = $medication->pet;
        $user = $pet->user;

        $preference = $this->getUserPreference($user->id, 'medication');
        $reminders = collect();

        $startDate = $medication->start_date;
        $endDate = $medication->end_date;
        $frequency = $medication->frequency_hours;

        $currentDate = $startDate;
        while ($currentDate <= $endDate) {
            $reminderDate = $currentDate->copy()->subDays($preference->days_before);

            if (!$reminderDate->isPast()) {
                $reminders->push(Reminder::create([
                    'user_id' => $user->id,
                    'pet_id' => $pet->id,
                    'type' => 'medication',
                    'title' => "Medicação: {$medication->name}",
                    'description' => "Hora de administrar {$medication->name} para {$pet->name}. Dosagem: {$medication->dosage}",
                    'due_date' => $currentDate,
                    'reminder_date' => $reminderDate,
                    'metadata' => ['medication_id' => $medication->id],
                ]));
            }

            $currentDate->addHours($frequency);
        }

        return $reminders;
    }

    public function createCheckupReminder(Pet $pet): Reminder
    {
        $user = $pet->user;
        $preference = $this->getUserPreference($user->id, 'checkup');

        $dueDate = now()->addYear();
        $reminderDate = $dueDate->copy()->subDays($preference->days_before);

        return Reminder::create([
            'user_id' => $user->id,
            'pet_id' => $pet->id,
            'type' => 'checkup',
            'title' => 'Checkup Anual',
            'description' => "Está na hora do checkup anual de {$pet->name}.",
            'due_date' => $dueDate,
            'reminder_date' => $reminderDate,
        ]);
    }

    public function sendReminder(Reminder $reminder): void
    {
        if ($reminder->isSent()) {
            return;
        }

        $type = $this->getNotificationType($reminder->type);

        $this->notificationService->sendNotification(
            $reminder->user,
            $type,
            $reminder->title,
            $reminder->description,
            [
                'reminder_id' => $reminder->id,
                'pet_id' => $reminder->pet_id,
                'type' => $reminder->type,
                'due_date' => $reminder->due_date->toDateString(),
            ]
        );

        $reminder->markAsSent();
    }

    public function getPendingReminders(int $userId): Collection
    {
        return Reminder::where('user_id', $userId)
            ->whereIn('status', ['pending', 'snoozed'])
            ->where(function ($query) {
                $query->where('reminder_date', '<=', now())
                      ->orWhere(function ($q) {
                          $q->where('status', 'snoozed')
                            ->where('snoozed_until', '<=', now());
                      });
            })
            ->with('pet')
            ->orderBy('due_date')
            ->get();
    }

    private function getUserPreference(int $userId, string $type): ReminderPreference
    {
        return ReminderPreference::firstOrCreate(
            ['user_id' => $userId, 'type' => $type],
            ['days_before' => 7]
        );
    }

    private function getNotificationType(string $reminderType): NotificationType
    {
        return match ($reminderType) {
            'vaccination' => NotificationType::VACCINATION_DUE,
            'medication' => NotificationType::MEDICATION_REMINDER,
            'checkup' => NotificationType::HEALTH_CHECKUP_DUE,
            default => NotificationType::REMINDER,
        };
    }
}

