<?php

namespace Tests\Unit\Appointment;

use App\Enums\RecurrenceFrequency;
use App\Services\Appointment\Recurrence\RecurrenceDateGenerator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Datas de série de agendamentos (PLANO 3.1). */
class RecurrenceDateGeneratorTest extends TestCase
{
    /** @return list<string> */
    private function dates(string $start, RecurrenceFrequency $freq, int $interval = 1, array $weekdays = [], ?string $until = null, ?int $count = null, ?string $window = null): array
    {
        return array_values(array_map(fn (CarbonImmutable $d): string => $d->toDateString(), (new RecurrenceDateGenerator)->dates(
            CarbonImmutable::parse($start), $freq, $interval, $weekdays, $until === null ? null : CarbonImmutable::parse($until), $count, $window === null ? null : CarbonImmutable::parse($window),
        )));
    }

    public function test_weekly_on_several_weekdays_starts_on_the_first_date_and_skips_earlier_days_of_that_week(): void
    {
        // 2026-11-04 é quarta: seg/qua/sex → qua 04, sex 06, seg 09, qua 11, sex 13.
        $this->assertSame(['2026-11-04', '2026-11-06', '2026-11-09', '2026-11-11', '2026-11-13'], $this->dates('2026-11-04', RecurrenceFrequency::WEEKLY, weekdays: [1, 3, 5], count: 5));
        $this->assertSame(['2026-11-04', '2026-11-11', '2026-11-18'], $this->dates('2026-11-04', RecurrenceFrequency::WEEKLY, count: 3));
    }

    public function test_biweekly_is_every_two_weeks_times_the_interval(): void
    {
        $this->assertSame(['2026-11-04', '2026-11-18', '2026-12-02'], $this->dates('2026-11-04', RecurrenceFrequency::BIWEEKLY, count: 3));
        $this->assertSame(['2026-11-04', '2026-12-02'], $this->dates('2026-11-04', RecurrenceFrequency::BIWEEKLY, interval: 2, count: 2));
    }

    public function test_monthly_by_day_31_falls_back_to_the_last_day_and_february_of_a_leap_year(): void
    {
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], $this->dates('2027-01-31', RecurrenceFrequency::MONTHLY_BY_DAY, count: 4));
        $this->assertSame(['2028-01-30', '2028-02-29', '2028-03-30'], $this->dates('2028-01-30', RecurrenceFrequency::MONTHLY_BY_DAY, count: 3));
        $this->assertSame(['2026-11-10', '2027-01-10'], $this->dates('2026-11-10', RecurrenceFrequency::MONTHLY_BY_DAY, interval: 2, count: 2));
    }

    public function test_monthly_by_weekday_second_tuesday_and_fifth_becomes_last(): void
    {
        // 2026-11-10 é a 2ª terça de novembro.
        $this->assertSame(['2026-11-10', '2026-12-08', '2027-01-12'], $this->dates('2026-11-10', RecurrenceFrequency::MONTHLY_BY_WEEKDAY, count: 3));
        // 2026-12-29 é a 5ª terça de dezembro → última terça: jan 26, fev 23, mar 30.
        $this->assertSame(['2026-12-29', '2027-01-26', '2027-02-23', '2027-03-30'], $this->dates('2026-12-29', RecurrenceFrequency::MONTHLY_BY_WEEKDAY, count: 4));
    }

    public function test_until_is_inclusive_count_wins_when_smaller_and_52_is_the_ceiling(): void
    {
        $this->assertSame(['2026-11-04', '2026-11-11', '2026-11-18'], $this->dates('2026-11-04', RecurrenceFrequency::WEEKLY, until: '2026-11-18'));
        $this->assertCount(52, $this->dates('2026-11-04', RecurrenceFrequency::WEEKLY, count: 80));
        $this->assertCount(52, $this->dates('2026-11-04', RecurrenceFrequency::WEEKLY, weekdays: [1, 3], until: '2028-12-31'));
        // Janela materializada corta as datas, os índices continuam os da série.
        $window = (new RecurrenceDateGenerator)->dates(CarbonImmutable::parse('2026-11-04'), RecurrenceFrequency::WEEKLY, 1, [], null, 10, CarbonImmutable::parse('2026-11-20'));
        $this->assertSame([1, 2, 3], array_keys($window));
    }
}
