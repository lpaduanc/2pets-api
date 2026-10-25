<?php

namespace App\Enums\Wallet;

use Carbon\CarbonImmutable;

/**
 * Frequência de um gasto que se repete (`tutor_recurring_expenses.frequency`).
 *
 * `next()` usa `addMonthNoOverflow`: um gasto todo dia 31 cai em 30/04 e 28/02, nunca
 * "pula" para 01/05 ou 03/03 como o `addMonth()` faria.
 */
enum ExpenseFrequency: string
{
    case WEEKLY = 'weekly';
    case BIWEEKLY = 'biweekly';
    case MONTHLY = 'monthly';
    case BIMONTHLY = 'bimonthly';
    case QUARTERLY = 'quarterly';
    case SEMIANNUAL = 'semiannual';
    case YEARLY = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::WEEKLY => 'Toda semana',
            self::BIWEEKLY => 'A cada 15 dias',
            self::MONTHLY => 'Todo mês',
            self::BIMONTHLY => 'A cada 2 meses',
            self::QUARTERLY => 'A cada 3 meses',
            self::SEMIANNUAL => 'A cada 6 meses',
            self::YEARLY => 'Todo ano',
        };
    }

    public function next(CarbonImmutable $from): CarbonImmutable
    {
        return match ($this) {
            self::WEEKLY => $from->addWeek(),
            self::BIWEEKLY => $from->addDays(14),
            self::MONTHLY => $from->addMonthNoOverflow(),
            self::BIMONTHLY => $from->addMonthsNoOverflow(2),
            self::QUARTERLY => $from->addMonthsNoOverflow(3),
            self::SEMIANNUAL => $from->addMonthsNoOverflow(6),
            self::YEARLY => $from->addYearNoOverflow(),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
