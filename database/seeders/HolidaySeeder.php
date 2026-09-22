<?php

namespace Database\Seeders;

use App\Enums\HolidayScope;
use App\Models\Holiday;
use App\Services\Finance\EasterCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Feriados nacionais fixos + móveis (calculados a partir da Páscoa) — contrato
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md, consumidos por
 * `DueDateService::nextBusinessDay()` e pela agenda (item 21).
 *
 * Grava em `App\Models\Holiday` (catálogo único do item 23, após a consolidação com o que era
 * `company_holidays`) com `organization_id`/`professional_id` os DOIS nulos — a única exceção
 * documentada à convenção "sempre um dono" desse catálogo (ver `Holiday::scopeVisibleTo()`).
 *
 * Idempotente: `firstOrCreate` por (data, nome) evita duplicar ao rodar de novo.
 */
class HolidaySeeder extends Seeder
{
    /** @var list<array{month: int, day: int, name: string}> */
    private const FIXED = [
        ['month' => 1, 'day' => 1, 'name' => 'Confraternização Universal'],
        ['month' => 4, 'day' => 21, 'name' => 'Tiradentes'],
        ['month' => 5, 'day' => 1, 'name' => 'Dia do Trabalho'],
        ['month' => 9, 'day' => 7, 'name' => 'Independência do Brasil'],
        ['month' => 10, 'day' => 12, 'name' => 'Nossa Senhora Aparecida'],
        ['month' => 11, 'day' => 2, 'name' => 'Finados'],
        ['month' => 11, 'day' => 15, 'name' => 'Proclamação da República'],
        ['month' => 12, 'day' => 25, 'name' => 'Natal'],
    ];

    /** Janela de anos para os feriados MÓVEIS — cobre o horizonte de parcelamento (até 60x). */
    private const MOVABLE_YEARS_AHEAD = 6;

    public function run(): void
    {
        $this->seedFixedHolidays();
        $this->seedMovableHolidays(app(EasterCalculator::class));
    }

    private function seedFixedHolidays(): void
    {
        foreach (self::FIXED as $holiday) {
            Holiday::firstOrCreate([
                'organization_id' => null,
                'professional_id' => null,
                'name' => $holiday['name'],
                'recurring_annually' => true,
            ], [
                'date' => Carbon::create(Carbon::now()->year, $holiday['month'], $holiday['day']),
                'scope' => HolidayScope::NATIONAL->value,
                'active' => true,
            ]);
        }
    }

    private function seedMovableHolidays(EasterCalculator $easter): void
    {
        $currentYear = (int) Carbon::now()->year;

        for ($year = $currentYear - 1; $year <= $currentYear + self::MOVABLE_YEARS_AHEAD; $year++) {
            $this->movableHolidaysFor($easter, $year)->each(
                fn (array $holiday) => Holiday::firstOrCreate([
                    'organization_id' => null,
                    'professional_id' => null,
                    'name' => $holiday['name'],
                    'date' => $holiday['date']->toDateString(),
                ], ['scope' => HolidayScope::NATIONAL->value, 'recurring_annually' => false, 'active' => true])
            );
        }
    }

    /** @return \Illuminate\Support\Collection<int, array{name: string, date: \Carbon\CarbonImmutable}> */
    private function movableHolidaysFor(EasterCalculator $easter, int $year): \Illuminate\Support\Collection
    {
        return collect([
            ['name' => 'Carnaval', 'date' => $easter->carnival($year)],
            ['name' => 'Sexta-Feira Santa', 'date' => $easter->goodFriday($year)],
            ['name' => 'Corpus Christi', 'date' => $easter->corpusChristi($year)],
        ]);
    }
}
