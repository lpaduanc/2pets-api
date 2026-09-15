<?php

namespace Database\Seeders;

use App\Models\Specialty;
use App\Support\Catalog\VeterinarySpecialtyCatalog;
use Illuminate\Database\Seeder;

/**
 * Semeia `specialties` a partir de `VeterinarySpecialtyCatalog`.
 *
 * A lista deixou de morar aqui porque tem um segundo consumidor: `ProfessionalOfferingMatrix`
 * usa o mesmo catálogo para decidir que especialidade cada tipo de profissional pode
 * declarar. Catálogo duplicado entre seeder e regra de negócio diverge — e a divergência
 * aparece como cadastro absurdo na busca.
 *
 * Ganho de carona: "Clinica Geral" passou a existir no catálogo. Era o rótulo MAIS gravado
 * em `professionals.specialties` e não tinha linha em `specialties` — ver o docblock do
 * catálogo.
 */
class SpecialtySeeder extends Seeder
{
    public function run(): void
    {
        $specialties = VeterinarySpecialtyCatalog::all();

        foreach ($specialties as $specialty) {
            Specialty::firstOrCreate(
                ['name' => $specialty['name']],
                ['name' => $specialty['name'], 'description' => $specialty['description']],
            );
        }

        $this->command?->info('Specialties seeded: '.count($specialties).' records.');
    }
}
