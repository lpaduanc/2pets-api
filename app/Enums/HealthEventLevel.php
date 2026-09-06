<?php

namespace App\Enums;

/**
 * Urgency bucket of a scheduled pet health event (next vaccine dose, next deworming).
 *
 * Thresholds mirror what the tutor's health dashboard renders today:
 *   due date in the past      → OVERDUE
 *   due within 30 days        → DUE_SOON
 *   due after that            → UPCOMING
 */
enum HealthEventLevel: string
{
    case OVERDUE = 'overdue';
    case DUE_SOON = 'due_soon';
    case UPCOMING = 'upcoming';

    private const DUE_SOON_THRESHOLD_DAYS = 30;

    public static function fromDaysUntil(int $daysUntil): self
    {
        if ($daysUntil < 0) {
            return self::OVERDUE;
        }

        if ($daysUntil <= self::DUE_SOON_THRESHOLD_DAYS) {
            return self::DUE_SOON;
        }

        return self::UPCOMING;
    }
}
