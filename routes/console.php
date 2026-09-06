<?php

use Illuminate\Support\Facades\Schedule;

// Health reminders - daily at 8:00 AM
Schedule::command('reminders:health')->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/health-reminders.log'));
