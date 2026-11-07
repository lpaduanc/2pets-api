<?php

namespace App\Services\Appointment\Recurrence;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * Datas de uma série de agendamentos (docs/gap-petflow/PLANO.md, 3.1), pura — sem banco. A 1ª
 * ocorrência é sempre a data inicial (`start`), índice 1; os índices seguem a ordem das datas.
 *
 *  - weekly: a cada `interval` semanas, nos `weekdays` (0 = domingo … 6 = sábado; vazio = o dia
 *    da semana do início). A semana começa no domingo; datas antes do início não entram;
 *  - biweekly: o mesmo, a cada 2 × `interval` semanas;
 *  - monthly_by_day: a cada `interval` meses, no dia do início; mês sem esse dia (31, 30, 29 de
 *    fevereiro fora de bissexto) → o último dia do mês;
 *  - monthly_by_weekday: a cada `interval` meses, na "n-ésima" ocorrência do dia da semana do
 *    início (n = ceil(dia / 7)); se o início é a 5ª, a série usa "a última" de cada mês.
 *
 * Fim: `until` (inclusive) OU `count`; nunca mais que 52 ocorrências (`MAX_OCCURRENCES`), nem
 * além de `$windowEnd` (a janela materializada — o resto nasce depois, pelo job).
 */
final class RecurrenceDateGenerator
{
    public const MAX_OCCURRENCES = 52;

    /** Teto de iterações para regra que não produz data (ex.: weekdays vazio mal formado). */
    private const MAX_PERIODS = 600;

    /**
     * @param  list<int>  $weekdays
     * @return array<int, CarbonImmutable> índice (1-based) => data
     */
    public function dates(CarbonImmutable $start, RecurrenceFrequency $freq, int $interval, array $weekdays, ?CarbonImmutable $until, ?int $count, ?CarbonImmutable $windowEnd = null): array
    {
        $start = $start->startOfDay();
        $limit = min($count ?? self::MAX_OCCURRENCES, self::MAX_OCCURRENCES);
        $result = [];

        foreach ($this->candidates($start, $freq, max(1, $interval), $weekdays) as $date) {
            if (count($result) >= $limit || ($until !== null && $date->gt($until->startOfDay()))) {
                break;
            }

            $result[count($result) + 1] = $date;
        }

        return $windowEnd === null ? $result : array_filter($result, fn (CarbonImmutable $d): bool => $d->lte($windowEnd->startOfDay()));
    }

    /** @return \Generator<int, CarbonImmutable> */
    private function candidates(CarbonImmutable $start, RecurrenceFrequency $freq, int $interval, array $weekdays): \Generator
    {
        if ($freq->usesWeekdays()) {
            yield from $this->weekly($start, $freq === RecurrenceFrequency::BIWEEKLY ? $interval * 2 : $interval, $weekdays);

            return;
        }

        $nth = (int) ceil($start->day / 7);

        for ($period = 0; $period < self::MAX_PERIODS; $period++) {
            $month = $start->startOfMonth()->addMonthsNoOverflow($period * $interval);

            yield $freq === RecurrenceFrequency::MONTHLY_BY_DAY
                ? $month->setDay(min($start->day, $month->daysInMonth))
                : $this->nthWeekday($month, $start->dayOfWeek, $nth >= 5 ? null : $nth);
        }
    }

    /** @return \Generator<int, CarbonImmutable> */
    private function weekly(CarbonImmutable $start, int $weeks, array $weekdays): \Generator
    {
        $days = array_values(array_unique(array_map('intval', $weekdays === [] ? [$start->dayOfWeek] : $weekdays)));
        sort($days);
        $weekStart = $start->subDays($start->dayOfWeek);

        for ($period = 0; $period < self::MAX_PERIODS; $period++) {
            foreach ($days as $day) {
                $date = $weekStart->addWeeks($period * $weeks)->addDays($day);

                if ($date->gte($start)) {
                    yield $date;
                }
            }
        }
    }

    /** `$nth` null = a última ocorrência do dia da semana no mês. */
    private function nthWeekday(CarbonImmutable $month, int $dayOfWeek, ?int $nth): CarbonImmutable
    {
        if ($nth === null) {
            $last = $month->endOfMonth()->startOfDay();

            return $last->subDays(($last->dayOfWeek - $dayOfWeek + 7) % 7);
        }

        $first = $month->addDays(($dayOfWeek - $month->dayOfWeek + 7) % 7);

        return $first->addWeeks($nth - 1);
    }
}
