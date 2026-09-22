<?php

namespace Tests\Feature\Finance;

use App\Models\Holiday;
use Database\Seeders\HolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeder de feriados nacionais — critério de aceite do
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md: feriados fixos + móveis (calculados a
 * partir da Páscoa) corretos para 2026 e 2027, e idempotente (rodar duas vezes não duplica).
 *
 * Grava em `App\Models\Holiday` (catálogo do item 23, consolidado com o que era
 * `company_holidays`) — ver `database/migrations/2026_10_30_700000_consolidate_holidays_with_company_holidays.php`.
 */
class HolidaySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_national_holidays_are_seeded(): void
    {
        (new HolidaySeeder)->run();

        $this->assertTrue(Holiday::whereNull('organization_id')->whereNull('professional_id')->where('name', 'Tiradentes')->exists());
        $this->assertTrue(Holiday::whereNull('organization_id')->whereNull('professional_id')->where('name', 'Natal')->exists());
        $this->assertSame(8, Holiday::whereNull('organization_id')->whereNull('professional_id')->where('recurring_annually', true)->count());
    }

    public function test_movable_holidays_are_computed_correctly_for_2026_and_2027(): void
    {
        (new HolidaySeeder)->run();

        $this->assertTrue(
            Holiday::where('name', 'Carnaval')->whereDate('date', '2026-02-17')->exists(),
            'Carnaval 2026 deveria cair em 17/02 (47 dias antes da Páscoa de 05/04/2026).'
        );
        $this->assertTrue(
            Holiday::where('name', 'Carnaval')->whereDate('date', '2027-02-09')->exists(),
            'Carnaval 2027 deveria cair em 09/02 (47 dias antes da Páscoa de 28/03/2027).'
        );
        $this->assertTrue(Holiday::where('name', 'Sexta-Feira Santa')->whereDate('date', '2026-04-03')->exists());
        $this->assertTrue(Holiday::where('name', 'Corpus Christi')->whereDate('date', '2026-06-04')->exists());
    }

    public function test_running_the_seeder_twice_does_not_duplicate_holidays(): void
    {
        (new HolidaySeeder)->run();
        $countAfterFirstRun = Holiday::count();

        (new HolidaySeeder)->run();

        $this->assertSame($countAfterFirstRun, Holiday::count());
    }
}
