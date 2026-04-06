<?php

namespace Database\Seeders;

use App\Models\FoodAllergy;
use Illuminate\Database\Seeder;

class FoodAllergySeeder extends Seeder
{
    public function run(): void
    {
        $allergies = [
            [
                'name' => 'Proteina de Frango',
                'description' => 'Alergia a proteina de frango, uma das alergias alimentares mais comuns em caes e gatos.',
            ],
            [
                'name' => 'Proteina de Carne Bovina',
                'description' => 'Alergia a proteina de carne bovina, frequente em caes, podendo causar dermatite e problemas gastrointestinais.',
            ],
            [
                'name' => 'Proteina de Peixe',
                'description' => 'Alergia a proteinas de peixes, menos comum mas que pode causar reacoes cutaneas e digestivas.',
            ],
            [
                'name' => 'Laticinios',
                'description' => 'Intolerancia ou alergia a proteinas do leite e derivados, causando diarreia e desconforto abdominal.',
            ],
            [
                'name' => 'Gluten/Trigo',
                'description' => 'Sensibilidade ao gluten ou proteinas do trigo, podendo causar inflamacao intestinal e dermatite.',
            ],
            [
                'name' => 'Soja',
                'description' => 'Alergia a proteina da soja, ingrediente comum em racoes, podendo causar coceira e problemas digestivos.',
            ],
            [
                'name' => 'Milho',
                'description' => 'Sensibilidade ao milho, cereal frequentemente utilizado em racoes como fonte de carboidrato.',
            ],
            [
                'name' => 'Ovo',
                'description' => 'Alergia a proteinas do ovo, podendo causar reacoes cutaneas e gastrointestinais.',
            ],
            [
                'name' => 'Cordeiro',
                'description' => 'Alergia a proteina de cordeiro, embora seja considerada uma proteina novel para muitos animais.',
            ],
            [
                'name' => 'Arroz',
                'description' => 'Sensibilidade ao arroz, embora rara, pode ocorrer em animais com multiplas alergias alimentares.',
            ],
        ];

        foreach ($allergies as $allergy) {
            FoodAllergy::firstOrCreate(
                ['name' => $allergy['name']],
                $allergy
            );
        }

        $this->command->info('Food allergies seeded: ' . count($allergies) . ' records.');
    }
}
