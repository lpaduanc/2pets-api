<?php

namespace Tests\Feature;

use App\Enums\ImmunizationGroup;
use App\Models\ImmunizationProduct;
use App\Models\VaccineCatalog;
use Database\Seeders\ImmunizationProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ImmunizationProductSeeder` fecha o gap de instalação nova: a migration de dado
 * `2026_10_06_100006_seed_immunization_products_from_vaccine_catalog.php` roda ANTES de
 * `VaccineCatalogSeeder` popular `vaccine_catalog` (mesmo achado documentado em
 * `DewormingMigrationTest`), então sem este seeder `immunization_products` fica vazia num
 * `db:seed` do zero — contrato docs/gap-simplesvet/contratos/13-contrato-api.md.
 */
class ImmunizationProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_copies_legacy_vaccine_catalog_rows_into_immunization_products(): void
    {
        VaccineCatalog::create([
            'name' => 'Antirrábica', 'species' => 'dog', 'doses_required' => 1,
            'booster_interval_days' => 365, 'description' => 'Obrigatória', 'required' => true,
        ]);
        VaccineCatalog::create([
            'name' => 'V4 Felina', 'species' => 'cat', 'doses_required' => 3,
            'interval_days' => 21, 'booster_interval_days' => 365, 'required' => false,
        ]);

        (new ImmunizationProductSeeder)->run();

        $this->assertDatabaseCount('immunization_products', 2);
        $this->assertDatabaseHas('immunization_products', [
            'name' => 'Antirrábica', 'group' => ImmunizationGroup::VACCINE->value,
            'organization_id' => null, 'legally_required' => true,
        ]);
        $this->assertDatabaseHas('immunization_product_species', ['species' => 'cat']);

        // `vaccine_catalog` continua intacta — não é dropada nem esvaziada por este seeder.
        $this->assertDatabaseCount('vaccine_catalog', 2);
    }

    public function test_running_the_seeder_twice_does_not_duplicate_products(): void
    {
        VaccineCatalog::create([
            'name' => 'V8', 'species' => 'dog', 'doses_required' => 3,
            'interval_days' => 21, 'booster_interval_days' => 365, 'required' => false,
        ]);

        (new ImmunizationProductSeeder)->run();
        $countAfterFirstRun = ImmunizationProduct::count();

        (new ImmunizationProductSeeder)->run();

        $this->assertSame($countAfterFirstRun, ImmunizationProduct::count());
    }

    public function test_skips_rows_with_a_species_outside_the_pet_species_enum(): void
    {
        VaccineCatalog::create([
            'name' => 'Vacina Equina', 'species' => 'horse', 'doses_required' => 1,
            'required' => false,
        ]);

        (new ImmunizationProductSeeder)->run();

        $this->assertDatabaseCount('immunization_products', 0);
    }
}
