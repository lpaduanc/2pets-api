<?php

namespace Database\Seeders;

use App\Models\Pathology;
use Illuminate\Database\Seeder;

class PathologySeeder extends Seeder
{
    /**
     * As 20 patologias originais são todas CRÔNICAS (`is_chronic = true`). A partir daqui o
     * catálogo ganha ~22 diagnósticos AGUDOS comuns (contrato
     * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §8,
     * `is_chronic = false`) — lista de prática de mercado, não estatística epidemiológica
     * verificada (o próprio doc de domínio marca isso), ponto de partida editável.
     *
     * Deliberadamente NÃO inserida via migration: este projeto sempre semeou catálogo por
     * Seeder (nunca `DB::table()->insert()` dentro de `up()`), e a suíte de teste roda
     * `RefreshDatabase` (migrations, nunca seeders) em toda classe — inserir linha fixa numa
     * migration faria a tabela "ganhar" 22 linhas de graça em QUALQUER teste que a toque,
     * quebrando contagem de testes existentes (`MasterDataTest`). Achado ao rodar teste manual
     * antes de fechar esta fatia.
     */
    public function run(): void
    {
        $pathologies = [
            [
                'name' => 'Diabetes Mellitus',
                'description' => 'Doenca metabolica caracterizada pela deficiencia na producao ou acao da insulina, resultando em hiperglicemia cronica.',
                'species' => null,
                'category' => 'metabolic',
                'is_chronic' => true,
            ],
            [
                'name' => 'Cardiopatia',
                'description' => 'Doenca cardiaca que compromete a funcao do coracao, podendo ser congenita ou adquirida.',
                'species' => null,
                'category' => 'cardiac',
                'is_chronic' => true,
            ],
            [
                'name' => 'Epilepsia',
                'description' => 'Disturbio neurologico caracterizado por convulsoes recorrentes devido a atividade eletrica anormal no cerebro.',
                'species' => null,
                'category' => 'neurological',
                'is_chronic' => true,
            ],
            [
                'name' => 'Hipertireoidismo',
                'description' => 'Producao excessiva de hormonios tireoidianos, comum em gatos idosos, causando perda de peso e hiperatividade.',
                'species' => 'cat',
                'category' => 'metabolic',
                'is_chronic' => true,
            ],
            [
                'name' => 'Hipotireoidismo',
                'description' => 'Producao insuficiente de hormonios tireoidianos, comum em caes, causando letargia, ganho de peso e problemas de pele.',
                'species' => 'dog',
                'category' => 'metabolic',
                'is_chronic' => true,
            ],
            [
                'name' => 'Artrite/Artrose',
                'description' => 'Doenca degenerativa das articulacoes que causa dor, rigidez e reducao da mobilidade.',
                'species' => null,
                'category' => 'musculoskeletal',
                'is_chronic' => true,
            ],
            [
                'name' => 'Doenca Renal Cronica',
                'description' => 'Perda progressiva e irreversivel da funcao renal, comum em gatos idosos e caes de idade avancada.',
                'species' => null,
                'category' => 'renal',
                'is_chronic' => true,
            ],
            [
                'name' => 'Displasia Coxofemoral',
                'description' => 'Ma formacao da articulacao do quadril, hereditaria, comum em caes de grande porte, causando dor e dificuldade de locomocao.',
                'species' => 'dog',
                'category' => 'musculoskeletal',
                'is_chronic' => true,
            ],
            [
                'name' => 'Obesidade',
                'description' => 'Acumulo excessivo de gordura corporal que compromete a saude e qualidade de vida do animal.',
                'species' => null,
                'category' => 'metabolic',
                'is_chronic' => true,
            ],
            [
                'name' => 'Alergia Cronica/Dermatite Atopica',
                'description' => 'Reacao alergica cronica que causa inflamacao da pele, coceira intensa e lesoes cutaneas recorrentes.',
                'species' => null,
                'category' => 'dermatological',
                'is_chronic' => true,
            ],
            [
                'name' => 'Doenca Periodontal',
                'description' => 'Infeccao das estruturas de suporte dos dentes (gengiva e osso), muito prevalente em caes e gatos.',
                'species' => null,
                'category' => 'dental',
                'is_chronic' => true,
            ],
            [
                'name' => 'Insuficiencia Cardiaca Congestiva',
                'description' => 'Condicao em que o coracao nao bombeia sangue de forma eficiente, causando acumulo de liquidos no corpo.',
                'species' => null,
                'category' => 'cardiac',
                'is_chronic' => true,
            ],
            [
                'name' => 'Pancreatite Cronica',
                'description' => 'Inflamacao persistente do pancreas que prejudica a digestao e pode causar dor abdominal recorrente.',
                'species' => null,
                'category' => 'gastrointestinal',
                'is_chronic' => true,
            ],
            [
                'name' => 'Doenca Inflamatoria Intestinal',
                'description' => 'Grupo de doencas que causam inflamacao cronica do trato gastrointestinal, resultando em vomitos e diarreia.',
                'species' => null,
                'category' => 'gastrointestinal',
                'is_chronic' => true,
            ],
            [
                'name' => 'Asma Felina',
                'description' => 'Doenca inflamatoria cronica das vias aereas inferiores dos gatos, causando tosse, sibilos e dificuldade respiratoria.',
                'species' => 'cat',
                'category' => 'respiratory',
                'is_chronic' => true,
            ],
            [
                'name' => 'Catarata',
                'description' => 'Opacificacao do cristalino do olho, causando reducao progressiva da visao, podendo levar a cegueira.',
                'species' => null,
                'category' => 'ophthalmological',
                'is_chronic' => true,
            ],
            [
                'name' => 'Glaucoma',
                'description' => 'Aumento da pressao intraocular que pode danificar o nervo optico e causar perda de visao.',
                'species' => null,
                'category' => 'ophthalmological',
                'is_chronic' => true,
            ],
            [
                'name' => 'Doenca de Cushing',
                'description' => 'Hiperadrenocorticismo causado pela producao excessiva de cortisol, comum em caes de meia-idade e idosos.',
                'species' => 'dog',
                'category' => 'metabolic',
                'is_chronic' => true,
            ],
            [
                'name' => 'Doenca de Addison',
                'description' => 'Hipoadrenocorticismo causado pela producao insuficiente de hormonios adrenais, podendo causar crises graves.',
                'species' => 'dog',
                'category' => 'metabolic',
                'is_chronic' => true,
            ],
            [
                'name' => 'Leishmaniose',
                'description' => 'Doenca infecciosa transmitida por flebotomineos, que afeta multiplos orgaos e requer tratamento prolongado.',
                'species' => 'dog',
                'category' => 'infectious',
                'is_chronic' => true,
            ],

            // ── Diagnósticos AGUDOS (contrato 05-*.md §8) ──────────────────────────
            ['name' => 'Gastroenterite', 'description' => 'Inflamação aguda do estômago e intestino, geralmente cursando com vômito e diarreia.', 'species' => null, 'category' => 'gastrointestinal', 'is_chronic' => false],
            ['name' => 'Otite Externa', 'description' => 'Inflamação ou infecção do canal auditivo externo, causando coceira, odor e secreção.', 'species' => null, 'category' => 'otologic', 'is_chronic' => false],
            ['name' => 'Conjuntivite', 'description' => 'Inflamação da conjuntiva ocular, causando vermelhidão e secreção nos olhos.', 'species' => null, 'category' => 'ophthalmological', 'is_chronic' => false],
            ['name' => 'Dermatite Alérgica', 'description' => 'Reação alérgica aguda da pele, com coceira e lesões cutâneas.', 'species' => null, 'category' => 'dermatological', 'is_chronic' => false],
            ['name' => 'Piometra', 'description' => 'Infecção uterina em fêmeas não castradas, potencialmente grave.', 'species' => null, 'category' => 'reproductive', 'is_chronic' => false],
            ['name' => 'Corpo Estranho Gastrointestinal', 'description' => 'Ingestão de objeto que obstrui ou lesiona o trato digestivo.', 'species' => null, 'category' => 'gastrointestinal', 'is_chronic' => false],
            ['name' => 'Intoxicação Alimentar', 'description' => 'Ingestão de alimento ou substância tóxica, causando sinais gastrointestinais ou sistêmicos.', 'species' => null, 'category' => 'toxicological', 'is_chronic' => false],
            ['name' => 'Infecção do Trato Urinário', 'description' => 'Infecção bacteriana da bexiga ou vias urinárias, causando dor e dificuldade para urinar.', 'species' => null, 'category' => 'urinary', 'is_chronic' => false],
            ['name' => 'Abscesso', 'description' => 'Coleção de pus sob a pele, geralmente após ferimento ou mordida.', 'species' => null, 'category' => 'dermatological', 'is_chronic' => false],
            ['name' => 'Fratura', 'description' => 'Quebra de osso por trauma, exigindo imobilização ou cirurgia.', 'species' => null, 'category' => 'musculoskeletal', 'is_chronic' => false],
            ['name' => 'Luxação de Patela', 'description' => 'Deslocamento da patela fora do sulco troclear, comum em cães de raça pequena.', 'species' => 'dog', 'category' => 'musculoskeletal', 'is_chronic' => false],
            ['name' => 'Verminose Intestinal', 'description' => 'Infestação por parasitas intestinais, causando diarreia e perda de peso.', 'species' => null, 'category' => 'gastrointestinal', 'is_chronic' => false],
            ['name' => 'Sarna (Escabiose)', 'description' => 'Infestação por ácaros na pele, causando coceira intensa e lesões cutâneas.', 'species' => null, 'category' => 'dermatological', 'is_chronic' => false],
            ['name' => 'Dermatofitose', 'description' => 'Infecção fúngica de pele e pelos (popularmente "micose"), transmissível a outros animais e humanos.', 'species' => null, 'category' => 'dermatological', 'is_chronic' => false],
            ['name' => 'Laceração/Ferimento', 'description' => 'Corte ou ferida aberta na pele, por trauma ou briga.', 'species' => null, 'category' => 'dermatological', 'is_chronic' => false],
            ['name' => 'Pancreatite Aguda', 'description' => 'Inflamação súbita do pâncreas, causando dor abdominal, vômito e prostração.', 'species' => null, 'category' => 'gastrointestinal', 'is_chronic' => false],
            ['name' => 'Gastrite', 'description' => 'Inflamação aguda da mucosa gástrica, causando vômito e desconforto abdominal.', 'species' => null, 'category' => 'gastrointestinal', 'is_chronic' => false],
            ['name' => 'Traqueobronquite Infecciosa Canina', 'description' => 'Infecção respiratória altamente contagiosa entre cães ("tosse dos canis"), causando tosse seca persistente.', 'species' => 'dog', 'category' => 'respiratory', 'is_chronic' => false],
            ['name' => 'Rinotraqueíte Felina', 'description' => 'Infecção viral respiratória alta em gatos, causando espirros, secreção nasal e ocular.', 'species' => 'cat', 'category' => 'respiratory', 'is_chronic' => false],
            ['name' => 'Complexo Respiratório Felino', 'description' => 'Quadro respiratório infeccioso comum em gatos, geralmente de origem viral ou bacteriana mista.', 'species' => 'cat', 'category' => 'respiratory', 'is_chronic' => false],
            ['name' => 'Cistite Idiopática Felina', 'description' => 'Inflamação da bexiga em gatos sem causa infecciosa identificada, ligada a estresse.', 'species' => 'cat', 'category' => 'urinary', 'is_chronic' => false],
            ['name' => 'Hérnia', 'description' => 'Deslocamento de órgão ou tecido através de abertura anormal na parede muscular.', 'species' => null, 'category' => 'surgical', 'is_chronic' => false],
            ['name' => 'Conjuntivite Alérgica', 'description' => 'Inflamação da conjuntiva por reação alérgica, sem origem infecciosa.', 'species' => null, 'category' => 'ophthalmological', 'is_chronic' => false],
        ];

        foreach ($pathologies as $pathology) {
            Pathology::firstOrCreate(
                ['name' => $pathology['name']],
                $pathology
            );
        }

        $this->command->info('Pathologies seeded: '.count($pathologies).' records.');
    }
}
