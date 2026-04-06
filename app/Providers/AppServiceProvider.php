<?php

namespace App\Providers;

use App\Events\ReviewCreated;
use App\Listeners\SendAppointmentNotification;
use App\Listeners\SendReviewNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(SendAppointmentNotification::class);
        Event::listen(ReviewCreated::class, SendReviewNotification::class);
    }
}
