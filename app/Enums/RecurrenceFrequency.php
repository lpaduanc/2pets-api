<?php

namespace App\Enums;

/** `appointment_series.freq` (PLANO 3.1). */
enum RecurrenceFrequency: string
{
    case WEEKLY = 'weekly';
    case BIWEEKLY = 'biweekly';
    case MONTHLY_BY_DAY = 'monthly_by_day';
    case MONTHLY_BY_WEEKDAY = 'monthly_by_weekday';

    public function label(): string
    {
        return match ($this) {
            self::WEEKLY => 'Semanal',
            self::BIWEEKLY => 'Quinzenal',
            self::MONTHLY_BY_DAY => 'Mensal (mesmo dia)',
            self::MONTHLY_BY_WEEKDAY => 'Mensal (mesmo dia da semana)',
        };
    }

    public function usesWeekdays(): bool
    {
        return $this === self::WEEKLY || $this === self::BIWEEKLY;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
