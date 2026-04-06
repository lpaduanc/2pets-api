<?php

namespace Database\Seeders;

use App\Models\Specialty;
use Illuminate\Database\Seeder;

class SpecialtySeeder extends Seeder
{
    public function run(): void
    {
        $specialties = [
            [
                'name' => 'Cardiologia',
                'description' => 'Diagnostico e tratamento de doencas do coracao e do sistema cardiovascular.',
            ],
            [
                'name' => 'Dermatologia',
                'description' => 'Diagnostico e tratamento de doencas da pele, pelos e unhas.',
            ],
            [
                'name' => 'Oftalmologia',
                'description' => 'Diagnostico e tratamento de doencas dos olhos e anexos oculares.',
            ],
            [
                'name' => 'Ortopedia',
                'description' => 'Diagnostico e tratamento de doencas do sistema musculoesqueletico, incluindo fraturas e luxacoes.',
            ],
            [
                'name' => 'Oncologia',
                'description' => 'Diagnostico e tratamento de tumores e neoplasias em animais.',
            ],
            [
                'name' => 'Neurologia',
                'description' => 'Diagnostico e tratamento de doencas do sistema nervoso central e periferico.',
            ],
            [
                'name' => 'Endocrinologia',
                'description' => 'Diagnostico e tratamento de doencas hormonais e do sistema endocrino.',
            ],
            [
                'name' => 'Cirurgia Geral',
                'description' => 'Procedimentos cirurgicos gerais, incluindo castracao, remocao de tumores e cirurgias abdominais.',
            ],
            [
                'name' => 'Anestesiologia',
                'description' => 'Especializacao em anestesia e controle da dor durante procedimentos cirurgicos.',
            ],
            [
                'name' => 'Patologia Clinica',
                'description' => 'Analise e interpretacao de exames laboratoriais para auxilio diagnostico.',
            ],
            [
                'name' => 'Reproducao Animal',
                'description' => 'Acompanhamento reprodutivo, inseminacao artificial e neonatologia veterinaria.',
            ],
            [
                'name' => 'Odontologia Veterinaria',
                'description' => 'Diagnostico e tratamento de doencas bucais, incluindo limpeza dentaria e exodontia.',
            ],
            [
                'name' => 'Nutricao Animal',
                'description' => 'Orientacao nutricional, formulacao de dietas e acompanhamento alimentar.',
            ],
            [
                'name' => 'Fisioterapia/Reabilitacao',
                'description' => 'Reabilitacao fisica pos-cirurgica, hidroterapia, acupuntura e terapias complementares.',
            ],
            [
                'name' => 'Comportamento Animal',
                'description' => 'Diagnostico e tratamento de disturbios comportamentais em animais domesticos.',
            ],
            [
                'name' => 'Medicina Felina',
                'description' => 'Atendimento especializado exclusivo para gatos, considerando suas particularidades fisiologicas.',
            ],
            [
                'name' => 'Medicina de Animais Silvestres/Exoticos',
                'description' => 'Atendimento de aves, repteis, roedores e outros animais silvestres e exoticos.',
            ],
            [
                'name' => 'Nefrologia/Urologia',
                'description' => 'Diagnostico e tratamento de doencas dos rins e do trato urinario.',
            ],
            [
                'name' => 'Gastroenterologia',
                'description' => 'Diagnostico e tratamento de doencas do trato gastrointestinal, figado e pancreas.',
            ],
            [
                'name' => 'Diagnostico por Imagem',
                'description' => 'Realizacao e interpretacao de exames de imagem: radiografia, ultrassonografia, tomografia e ressonancia.',
            ],
        ];

        foreach ($specialties as $specialty) {
            Specialty::firstOrCreate(
                ['name' => $specialty['name']],
                $specialty
            );
        }

        $this->command->info('Specialties seeded: ' . count($specialties) . ' records.');
    }
}
