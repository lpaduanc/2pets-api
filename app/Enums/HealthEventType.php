<?php

namespace App\Enums;

/**
 * Kinds of scheduled health event that feed the tutor's health roll-up.
 * Both carry a "next date" the tutor must act on.
 */
enum HealthEventType: string
{
    case VACCINATION = 'vaccination';
    case DEWORMING = 'deworming';
}
