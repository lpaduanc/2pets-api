<?php

namespace App\Enums;

enum WaitlistStatus: string
{
    case ACTIVE = 'active';
    case NOTIFIED = 'notified';
    case BOOKED = 'booked';
    case CANCELLED = 'cancelled';
}

