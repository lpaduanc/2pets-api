<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Appointment $appointment
    ) {}
}
