<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\Vaccination;
use App\Services\Reminder\HealthReminderService;
use Carbon\Carbon;
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

