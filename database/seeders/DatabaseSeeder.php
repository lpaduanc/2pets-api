<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * NÃO reintroduza `WithoutModelEvents` aqui. Os seeders deste projeto
     * DEPENDEM dos eventos de model para produzir linhas válidas:
     *
     *   - `Pet::creating` gera o `public_id` (NOT NULL, sem default no banco);
     *     com os eventos mudos o `PetSeeder` estoura com 23502 na primeira linha.
     *   - O trait `RefreshesPermissionCache` do spatie/laravel-permission só
     *     invalida o cache de permissions por `saved`/`deleted`; sem eles o
     *     `RolesAndPermissionsSeeder` lê a coleção vazia que ficou no Redis e
     *     estoura com "There is no permission named `users.view`".
     *
     * Os observers registrados em `AppServiceProvider` (cache de busca,
     * especialidades) são idempotentes e apenas invalidam cache — rodá-los
     * durante o seed é inofensivo.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BreedSeeder::class,
            PathologySeeder::class,
            VaccineCatalogSeeder::class,
            // Copia `vaccine_catalog` (linha acima) para `immunization_products` — os
            // leitores atuais (`MasterDataController`, `VaccinationImportValidator`) não
            // leem mais `vaccine_catalog` diretamente (contrato 13).
            ImmunizationProductSeeder::class,
            FoodBrandSeeder::class,
            SpecialtySeeder::class,
            FoodAllergySeeder::class,
            DietaryRestrictionSeeder::class,
            PetSeeder::class,
            ProfessionalSeeder::class,
            MedicalDataSeeder::class,
            SubscriptionPlanSeeder::class,
            CouponSeeder::class,
            DemoDataSeeder::class,
            ClinicOrganizationSeeder::class,
            HolidaySeeder::class,
            // Depende dos e-mails que `ProfessionalSeeder`/`DemoDataSeeder` já criaram —
            // por último de propósito.
            AvailabilitySeeder::class,
        ]);
    }
}
