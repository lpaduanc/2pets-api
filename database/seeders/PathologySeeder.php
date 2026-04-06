<?php

namespace Database\Seeders;

use App\Models\Pathology;
use Illuminate\Database\Seeder;

class PathologySeeder extends Seeder
{
    public function run(): void
    {
        $pathologies = [
            [
                'name' => 'Diabetes Mellitus',
                'description' => 'Doenca metabolica caracterizada pela deficiencia na producao ou acao da insulina, resultando em hiperglicemia cronica.',
                'species' => null,
                'category' => 'metabolic',
            ],
            [
                'name' => 'Cardiopatia',
                'description' => 'Doenca cardiaca que compromete a funcao do coracao, podendo ser congenita ou adquirida.',
                'species' => null,
                'category' => 'cardiac',
            ],
            [
                'name' => 'Epilepsia',
                'description' => 'Disturbio neurologico caracterizado por convulsoes recorrentes devido a atividade eletrica anormal no cerebro.',
                'species' => null,
                'category' => 'neurological',
            ],
            [
                'name' => 'Hipertireoidismo',
                'description' => 'Producao excessiva de hormonios tireoidianos, comum em gatos idosos, causando perda de peso e hiperatividade.',
                'species' => 'cat',
                'category' => 'metabolic',
            ],
            [
                'name' => 'Hipotireoidismo',
                'description' => 'Producao insuficiente de hormonios tireoidianos, comum em caes, causando letargia, ganho de peso e problemas de pele.',
                'species' => 'dog',
                'category' => 'metabolic',
            ],
            [
                'name' => 'Artrite/Artrose',
                'description' => 'Doenca degenerativa das articulacoes que causa dor, rigidez e reducao da mobilidade.',
                'species' => null,
                'category' => 'musculoskeletal',
            ],
            [
                'name' => 'Doenca Renal Cronica',
                'description' => 'Perda progressiva e irreversivel da funcao renal, comum em gatos idosos e caes de idade avancada.',
                'species' => null,
                'category' => 'renal',
            ],
            [
                'name' => 'Displasia Coxofemoral',
                'description' => 'Ma formacao da articulacao do quadril, hereditaria, comum em caes de grande porte, causando dor e dificuldade de locomocao.',
                'species' => 'dog',
                'category' => 'musculoskeletal',
            ],
            [
                'name' => 'Obesidade',
                'description' => 'Acumulo excessivo de gordura corporal que compromete a saude e qualidade de vida do animal.',
                'species' => null,
                'category' => 'metabolic',
            ],
            [
                'name' => 'Alergia Cronica/Dermatite Atopica',
                'description' => 'Reacao alergica cronica que causa inflamacao da pele, coceira intensa e lesoes cutaneas recorrentes.',
                'species' => null,
                'category' => 'dermatological',
            ],
            [
                'name' => 'Doenca Periodontal',
                'description' => 'Infeccao das estruturas de suporte dos dentes (gengiva e osso), muito prevalente em caes e gatos.',
                'species' => null,
                'category' => 'dental',
            ],
            [
                'name' => 'Insuficiencia Cardiaca Congestiva',
                'description' => 'Condicao em que o coracao nao bombeia sangue de forma eficiente, causando acumulo de liquidos no corpo.',
                'species' => null,
                'category' => 'cardiac',
            ],
            [
                'name' => 'Pancreatite Cronica',
                'description' => 'Inflamacao persistente do pancreas que prejudica a digestao e pode causar dor abdominal recorrente.',
                'species' => null,
                'category' => 'gastrointestinal',
            ],
            [
                'name' => 'Doenca Inflamatoria Intestinal',
                'description' => 'Grupo de doencas que causam inflamacao cronica do trato gastrointestinal, resultando em vomitos e diarreia.',
                'species' => null,
                'category' => 'gastrointestinal',
            ],
            [
                'name' => 'Asma Felina',
                'description' => 'Doenca inflamatoria cronica das vias aereas inferiores dos gatos, causando tosse, sibilos e dificuldade respiratoria.',
                'species' => 'cat',
                'category' => 'respiratory',
            ],
            [
                'name' => 'Catarata',
                'description' => 'Opacificacao do cristalino do olho, causando reducao progressiva da visao, podendo levar a cegueira.',
                'species' => null,
                'category' => 'ophthalmological',
            ],
            [
                'name' => 'Glaucoma',
                'description' => 'Aumento da pressao intraocular que pode danificar o nervo optico e causar perda de visao.',
                'species' => null,
                'category' => 'ophthalmological',
            ],
            [
                'name' => 'Doenca de Cushing',
                'description' => 'Hiperadrenocorticismo causado pela producao excessiva de cortisol, comum em caes de meia-idade e idosos.',
                'species' => 'dog',
                'category' => 'metabolic',
            ],
            [
                'name' => 'Doenca de Addison',
                'description' => 'Hipoadrenocorticismo causado pela producao insuficiente de hormonios adrenais, podendo causar crises graves.',
                'species' => 'dog',
                'category' => 'metabolic',
            ],
            [
                'name' => 'Leishmaniose',
                'description' => 'Doenca infecciosa transmitida por flebotomineos, que afeta multiplos orgaos e requer tratamento prolongado.',
                'species' => 'dog',
                'category' => 'infectious',
            ],
        ];

        foreach ($pathologies as $pathology) {
            Pathology::firstOrCreate(
                ['name' => $pathology['name']],
                $pathology
            );
        }

        $this->command->info('Pathologies seeded: ' . count($pathologies) . ' records.');
    }
}
