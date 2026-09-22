<?php

namespace Database\Seeders;

use App\Enums\ImmunizationGroup;
use App\Enums\PetSpecies;
use App\Models\ImmunizationProduct;
use App\Models\VaccineCatalog;
use Illuminate\Database\Seeder;

/**
 * Copia o catálogo global de `vaccine_catalog` (seedado por `VaccineCatalogSeeder`, que
 * roda logo antes) para `immunization_products` — mesma cópia 1:1 que a migration de dado
 * `2026_10_06_100006_seed_immunization_products_from_vaccine_catalog.php` faz. Este seeder
 * é necessário porque, em uma instalação nova, aquela migration roda ANTES deste seeder,
 * quando `vaccine_catalog` ainda está vazia (achado documentado em
 * `tests/Feature/DewormingMigrationTest.php`). Sem ele, `immunization_products` fica vazia
 * em qualquer ambiente que só rode `db:seed` a partir do zero, e os leitores atuais
 * (`MasterDataController::vaccineCatalog`, `VaccinationImportValidator`) devolveriam lista
 * vazia mesmo com `vaccine_catalog` populada.
 *
 * Idempotente via `firstOrCreate`: seguro rodar `db:seed` mais de uma vez.
 */
class ImmunizationProductSeeder extends Seeder
{
    public function run(): void
    {
        $copied = 0;

        VaccineCatalog::query()->orderBy('id')->each(function (VaccineCatalog $legacyVaccine) use (&$copied): void {
            if ($this->copyLegacyVaccine($legacyVaccine)) {
                $copied++;
            }
        });

        $this->command?->info("Immunization products seeded from vaccine catalog: {$copied} records.");
    }

    private function copyLegacyVaccine(VaccineCatalog $legacyVaccine): bool
    {
        $species = PetSpecies::tryFrom($legacyVaccine->species);
        if ($species === null) {
            return false;
        }

        $product = ImmunizationProduct::firstOrCreate(
            [
                'organization_id' => null,
                'name' => $legacyVaccine->name,
                'group' => ImmunizationGroup::VACCINE,
            ],
            [
                'manufacturer' => null,
                'description' => $legacyVaccine->description,
                'legally_required' => $legacyVaccine->required,
                'active' => true,
            ],
        );

        $product->speciesLinks()->firstOrCreate(['species' => $species]);

        return true;
    }
}
