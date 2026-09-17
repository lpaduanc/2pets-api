<?php

namespace App\DataTransferObjects\Professional;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\User;

final readonly class NewPatientAppointmentResult
{
    public function __construct(
        public Appointment $appointment,
        public Pet $pet,
        public User $tutor,
        public bool $claimLinkSent,
    ) {}
}
