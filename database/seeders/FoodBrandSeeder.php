<?php

namespace Database\Seeders;

use App\Models\FoodBrand;
use Illuminate\Database\Seeder;

class FoodBrandSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            ['name' => 'Royal Canin', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Purina Pro Plan', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => "Hill's Science Diet", 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Pedigree', 'type' => 'dry', 'species_target' => 'dog'],
            ['name' => 'Whiskas', 'type' => 'dry', 'species_target' => 'cat'],
            ['name' => 'Premier Pet', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Golden', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'N&D Farmina', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Guabi Natural', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'True', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Gran Plus', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Biofresh', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Equilibrio', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Special Dog', 'type' => 'dry', 'species_target' => 'dog'],
            ['name' => 'Special Cat', 'type' => 'dry', 'species_target' => 'cat'],
            ['name' => 'Dog Chow', 'type' => 'dry', 'species_target' => 'dog'],
            ['name' => 'Cat Chow', 'type' => 'dry', 'species_target' => 'cat'],
            ['name' => 'Frost', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Acana', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Orijen', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Taste of the Wild', 'type' => 'dry', 'species_target' => 'all'],
            ['name' => 'Nutropica', 'type' => 'dry', 'species_target' => null],
        ];

        foreach ($brands as $brand) {
            FoodBrand::firstOrCreate(
                ['name' => $brand['name']],
                $brand
            );
        }

        $this->command->info('Food brands seeded: ' . count($brands) . ' records.');
    }
}
