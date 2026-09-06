<?php

namespace App\Console\Commands;

use App\Models\PetDeworming;
use App\Models\Reminder;
use App\Models\Vaccination;
use App\Notifications\DewormingReminderNotification;
use App\Notifications\VaccineOverdueNotification;
use App\Notifications\VaccineReminderNotification;
use App\Services\Reminder\HealthReminderService;
use Illuminate\Console\Command;

class SendHealthReminders extends Command
{
    protected $signature = 'reminders:health';

    protected $description = 'Send health reminders (vaccinations, medications, checkups)';

    public function __construct(
        private readonly HealthReminderService $reminderService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Processing health reminders...');

        $this->processVaccinationReminders();
        $this->sendVaccineNotifications();
        $this->sendDewormingNotifications();
        $this->processPendingReminders();

        $this->info('Health reminders processed.');

        return Command::SUCCESS;
    }

    private function processVaccinationReminders(): void
    {
        $upcomingVaccinations = Vaccination::whereNotNull('next_dose_date')
            ->where('next_dose_date', '>=', now())
            ->where('next_dose_date', '<=', now()->addDays(30))
            ->whereDoesntHave('reminders', function ($query) {
                $query->where('type', 'vaccination')
                    ->whereIn('status', ['pending', 'sent']);
            })
            ->with(['pet.user'])
            ->get();

        foreach ($upcomingVaccinations as $vaccination) {
            try {
                $this->reminderService->createVaccinationReminder($vaccination);
                $this->info("Created reminder for vaccination: {$vaccination->vaccine_name}");
            } catch (\Exception $e) {
                $this->error("Failed to create vaccination reminder: {$e->getMessage()}");
            }
        }
    }

    private function sendVaccineNotifications(): void
    {
        // Vaccines expiring in 7 days
        $upcoming = Vaccination::whereNotNull('next_dose_date')
            ->whereDate('next_dose_date', now()->addDays(7)->toDateString())
            ->with(['pet.user'])
            ->get();

        foreach ($upcoming as $vaccination) {
            if ($vaccination->pet?->user) {
                $daysUntil = (int) now()->diffInDays($vaccination->next_dose_date);
                $vaccination->pet->user->notify(new VaccineReminderNotification(
                    $vaccination->pet->name,
                    $vaccination->vaccine_name,
                    $daysUntil,
                    $vaccination->next_dose_date->format('d/m/Y')
                ));
                $this->info("Sent vaccine reminder for {$vaccination->pet->name}");
            }
        }

        // Vaccines overdue (today)
        $overdue = Vaccination::whereNotNull('next_dose_date')
            ->whereDate('next_dose_date', now()->toDateString())
            ->with(['pet.user'])
            ->get();

        foreach ($overdue as $vaccination) {
            if ($vaccination->pet?->user) {
                $vaccination->pet->user->notify(new VaccineOverdueNotification(
                    $vaccination->pet->name,
                    $vaccination->vaccine_name,
                    $vaccination->next_dose_date->format('d/m/Y')
                ));
                $this->info("Sent vaccine overdue alert for {$vaccination->pet->name}");
            }
        }
    }

    private function sendDewormingNotifications(): void
    {
        $upcoming = PetDeworming::whereNotNull('next_date')
            ->whereDate('next_date', now()->addDays(7)->toDateString())
            ->with(['pet.user'])
            ->get();

        foreach ($upcoming as $deworming) {
            if ($deworming->pet?->user) {
                $deworming->pet->user->notify(new DewormingReminderNotification(
                    $deworming->pet->name,
                    $deworming->next_date->format('d/m/Y')
                ));
                $this->info("Sent deworming reminder for {$deworming->pet->name}");
            }
        }
    }

    private function processPendingReminders(): void
    {
        $reminders = Reminder::where('status', 'pending')
            ->where('reminder_date', '<=', now())
            ->with(['user', 'pet'])
            ->get();

        foreach ($reminders as $reminder) {
            try {
                $this->reminderService->sendReminder($reminder);
                $this->info("Sent reminder: {$reminder->title} to {$reminder->user->name}");
            } catch (\Exception $e) {
                $this->error("Failed to send reminder {$reminder->id}: {$e->getMessage()}");
            }
        }

        // Process snoozed reminders
        $snoozedReminders = Reminder::where('status', 'snoozed')
            ->where('snoozed_until', '<=', now())
            ->with(['user', 'pet'])
            ->get();

        foreach ($snoozedReminders as $reminder) {
            $reminder->update(['status' => 'pending']);
            $this->info("Reactivated snoozed reminder: {$reminder->title}");
        }
    }
}
