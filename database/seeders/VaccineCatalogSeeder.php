<?php

namespace Database\Seeders;

use App\Models\VaccineCatalog;
use Illuminate\Database\Seeder;

class VaccineCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $vaccines = [
            // ─── Dogs ───
            [
                'name' => 'V8/V10 (Multipla Canina)',
                'species' => 'dog',
                'doses_required' => 3,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina polivalente canina que protege contra cinomose, parvovirose, coronavirose, hepatite infecciosa, adenovirose, parainfluenza e leptospirose.',
                'required' => false,
            ],
            [
                'name' => 'V11 (Multipla Canina)',
                'species' => 'dog',
                'doses_required' => 3,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina polivalente canina com protecao ampliada, incluindo mais sorovares de leptospirose alem das doencas da V8/V10.',
                'required' => false,
            ],
            [
                'name' => 'Antirrabica (Raiva)',
                'species' => 'dog',
                'doses_required' => 1,
                'interval_days' => null,
                'booster_interval_days' => 365,
                'description' => 'Vacina contra raiva, obrigatoria por lei. Dose unica com reforco anual.',
                'required' => true,
            ],
            [
                'name' => 'Gripe Canina (Tosse dos Canis)',
                'species' => 'dog',
                'doses_required' => 2,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina contra traqueobronquite infecciosa canina (Bordetella bronchiseptica e Parainfluenza).',
                'required' => false,
            ],
            [
                'name' => 'Giardia',
                'species' => 'dog',
                'doses_required' => 2,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina contra Giardia lamblia, parasita intestinal que causa diarreia e ma absorcao de nutrientes.',
                'required' => false,
            ],
            [
                'name' => 'Leishmaniose',
                'species' => 'dog',
                'doses_required' => 3,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina contra leishmaniose visceral canina, recomendada em areas endemicas.',
                'required' => false,
            ],

            // ─── Cats ───
            [
                'name' => 'V3 (Triplice Felina)',
                'species' => 'cat',
                'doses_required' => 3,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina triplice felina que protege contra panleucopenia, rinotraqueite e calicivirose.',
                'required' => false,
            ],
            [
                'name' => 'V4 (Quadrupla Felina)',
                'species' => 'cat',
                'doses_required' => 3,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina quadrupla felina que inclui protecao da V3 mais Chlamydophila felis.',
                'required' => false,
            ],
            [
                'name' => 'V5 (Quintupla Felina)',
                'species' => 'cat',
                'doses_required' => 3,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina quintupla felina que inclui protecao da V4 mais leucemia felina (FeLV).',
                'required' => false,
            ],
            [
                'name' => 'Antirrabica (Raiva)',
                'species' => 'cat',
                'doses_required' => 1,
                'interval_days' => null,
                'booster_interval_days' => 365,
                'description' => 'Vacina contra raiva, obrigatoria por lei. Dose unica com reforco anual.',
                'required' => true,
            ],
            [
                'name' => 'FeLV (Leucemia Felina)',
                'species' => 'cat',
                'doses_required' => 2,
                'interval_days' => 21,
                'booster_interval_days' => 365,
                'description' => 'Vacina contra o virus da leucemia felina, recomendada para gatos com acesso a rua ou contato com outros gatos.',
                'required' => false,
            ],
        ];

        foreach ($vaccines as $vaccine) {
            VaccineCatalog::firstOrCreate(
                ['name' => $vaccine['name'], 'species' => $vaccine['species']],
                $vaccine
            );
        }

        $this->command->info('Vaccine catalog seeded: ' . count($vaccines) . ' records.');
    }
}
