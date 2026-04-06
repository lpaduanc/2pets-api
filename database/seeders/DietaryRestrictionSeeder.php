<?php

namespace Database\Seeders;

use App\Models\DietaryRestriction;
use Illuminate\Database\Seeder;

class DietaryRestrictionSeeder extends Seeder
{
    public function run(): void
    {
        $restrictions = [
            [
                'name' => 'Dieta hipoalergenica',
                'description' => 'Para animais com multiplas alergias alimentares confirmadas.',
            ],
            [
                'name' => 'Low-fat (Baixa gordura)',
                'description' => 'Para animais com pancreatite, obesidade ou doencas hepaticas.',
            ],
            [
                'name' => 'High-protein (Alta proteina)',
                'description' => 'Para animais com necessidades proteicas elevadas, como atletas ou em recuperacao.',
            ],
            [
                'name' => 'Dieta renal',
                'description' => 'Para animais com doenca renal cronica, restricao de fosforo e proteina.',
            ],
            [
                'name' => 'Dieta hepatica',
                'description' => 'Para animais com insuficiencia hepatica, baixa em cobre e proteina moderada.',
            ],
            [
                'name' => 'Dieta para diabeticos',
                'description' => 'Para animais diabeticos, rica em fibras e baixa em carboidratos simples.',
            ],
            [
                'name' => 'Dieta cardiaca',
                'description' => 'Para animais cardiopatas, baixa em sodio.',
            ],
            [
                'name' => 'Grain-free (Sem graos)',
                'description' => 'Dieta sem cereais como trigo, milho, arroz ou aveia.',
            ],
            [
                'name' => 'Dieta para filhotes',
                'description' => 'Formulacao especifica para crescimento, com nutrientes para desenvolvimento osseo.',
            ],
            [
                'name' => 'Dieta para idosos',
                'description' => 'Formulacao para animais senior, facil digestao e suporte articular.',
            ],
            [
                'name' => 'Dieta de exclusao',
                'description' => 'Usada em testes alergenicos, com fonte unica de proteina e carboidrato.',
            ],
            [
                'name' => 'Dieta natural/BARF',
                'description' => 'Alimentacao natural crua biologicamente apropriada.',
            ],
        ];

        foreach ($restrictions as $restriction) {
            DietaryRestriction::firstOrCreate(
                ['name' => $restriction['name']],
                $restriction
            );
        }

        $this->command->info('Dietary restrictions seeded: ' . count($restrictions) . ' records.');
    }
}
