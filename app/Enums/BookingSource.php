<?php

namespace App\Enums;

enum BookingSource: string
{
    case PROFESSIONAL = 'professional';
    case CLIENT = 'client';
    case ADMIN = 'admin';
}

