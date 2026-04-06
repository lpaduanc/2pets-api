<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BreedSeeder::class,
            PathologySeeder::class,
            VaccineCatalogSeeder::class,
            FoodBrandSeeder::class,
            SpecialtySeeder::class,
            FoodAllergySeeder::class,
            DietaryRestrictionSeeder::class,
            PetSeeder::class,
            ProfessionalSeeder::class,
            MedicalDataSeeder::class,
        ]);
    }
}
