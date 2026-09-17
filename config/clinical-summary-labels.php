<?php

/**
 * Rótulos em pt-BR usados EXCLUSIVAMENTE por `MedicalRecordSummaryPreviewService` para montar
 * o texto de `POST professional/medical-records/{id}/summary-preview` (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §6).
 *
 * Deliberadamente separado de `config/clinical-parameters.php`: aquele arquivo é taxonomia de
 * VALIDAÇÃO (quais valores um campo aceita); este é apresentação — texto para o tutor leigo ler.
 * Misturar os dois faria `clinical-parameters.php` parar de ser "taxonomia pura" (seu próprio
 * docblock já declara essa fronteira).
 *
 * `sign_labels` cobre TANTO `anamnesis_signs.sign` quanto `chief_complaint` — os dois campos
 * compartilham a maior parte do vocabulário de propósito (contrato §3.1: "é a mesma coisa dita
 * em lugares diferentes"), então um rótulo só, nunca duplicado.
 *
 * Cobertura deliberadamente parcial: `item = other` de `treatment_actions` não tem rótulo (o
 * texto real está em `notes`, que o template não cita para não expor jargão sem contexto) — a
 * frase de conduta simplesmente omite esse item. Mesmo raciocínio de "cobrir menos e ler bem"
 * do contrato §6.2.
 */

return [

    'sign_labels' => [
        'vomiting' => 'vômito',
        'diarrhea' => 'diarreia',
        'anorexia' => 'falta de apetite',
        'constipation' => 'prisão de ventre',
        'abdominal_distension' => 'barriga inchada',
        'excessive_drooling' => 'salivação excessiva',
        'cough_sneeze' => 'tosse ou espirro',
        'nasal_discharge' => 'secreção pelo nariz',
        'noisy_breathing' => 'respiração ruidosa',
        'respiratory_distress' => 'dificuldade respiratória',
        'pruritus' => 'coceira',
        'hair_loss' => 'queda de pelo',
        'skin_odor' => 'mau cheiro na pele',
        'lump_or_mass' => 'um caroço ou nódulo',
        'skin_wound' => 'uma ferida na pele',
        'lameness' => 'claudicação (mancando)',
        'difficulty_rising' => 'dificuldade para se levantar',
        'tremors' => 'tremores',
        'pain_on_touch' => 'dor ao toque',
        'seizure' => 'convulsão',
        'disorientation' => 'desorientação',
        'head_tilt' => 'cabeça torta',
        'loss_of_balance' => 'desequilíbrio',
        'ocular_discharge' => 'secreção nos olhos',
        'red_eye' => 'olho vermelho',
        'excessive_blinking' => 'piscar excessivo',
        'cloudy_eye' => 'olho opaco',
        'aural_discharge' => 'secreção no ouvido',
        'head_shaking' => 'balançar de cabeça',
        'ear_odor' => 'mau cheiro no ouvido',
        'dysuria' => 'dor para urinar',
        'hematuria' => 'sangue na urina',
        'inappropriate_elimination' => 'urinar fora do lugar de costume',
        'polyuria' => 'urinar mais que o normal',
        'oliguria' => 'urinar pouco ou nada',
        'lethargy' => 'apatia',
        'weight_change' => 'alteração de peso',
        'perceived_fever' => 'febre percebida pelo tutor',
        'swollen_lymph_nodes' => 'ínguas no pescoço',
        'behavior_change' => 'mudança de comportamento',
        'bad_breath_dental' => 'mau hálito',
        'litter_box_change' => 'mudança no uso da caixa de areia',
        'hairball' => 'bola de pelo',
        // Exclusivos de chief_complaint (não têm modificador de anamnese associado).
        'routine_checkup' => 'consulta de rotina',
        'revaccination' => 'revacinação',
        'elective_neutering' => 'castração eletiva',
        'pre_anesthetic_evaluation' => 'avaliação pré-anestésica',
        'post_surgical_followup' => 'retorno pós-cirúrgico',
        'other' => 'motivo relatado pelo tutor',
    ],

    'onset_labels' => [
        'lt_24h' => 'há menos de 24 horas',
        '1_3_days' => 'há 1 a 3 dias',
        '4_7_days' => 'há 4 a 7 dias',
        '1_4_weeks' => 'há 1 a 4 semanas',
        'gt_1_month' => 'há mais de 1 mês',
        'chronic_recurrent' => 'de forma recorrente',
    ],

    'behavior_findings' => [
        'appetite' => ['reduced' => 'apetite reduzido', 'increased' => 'apetite aumentado', 'absent' => 'ausência de apetite'],
        'water_intake' => ['increased' => 'aumento na ingestão de água', 'reduced' => 'redução na ingestão de água'],
        'activity_level' => ['reduced' => 'queda na disposição', 'increased' => 'agitação'],
        'sleep_pattern' => ['increased' => 'sono aumentado', 'restless' => 'sono agitado'],
        'social_interaction' => ['withdrawn' => 'retraimento social', 'aggressive' => 'agressividade', 'clingy' => 'mais carente que o normal'],
        'vocalization' => ['increased' => 'vocalização aumentada', 'unusual_sounds' => 'sons incomuns', 'absent' => 'vocalização ausente'],
    ],

    /**
     * Só os valores FORA da baseline de cada sistema (ver `physical_exam_baseline` abaixo)
     * precisam de rótulo — o achado normal nunca aparece no resumo.
     */
    'physical_exam' => [
        'general_state' => ['apathetic' => 'apatia', 'prostrate' => 'prostração', 'comatose' => 'estado comatoso'],
        'mucous_membranes' => [
            'pale' => 'mucosas pálidas',
            'hypochromic_1' => 'mucosas levemente hipocoradas',
            'hypochromic_2' => 'mucosas hipocoradas',
            'hypochromic_3' => 'mucosas bastante hipocoradas',
            'hypochromic_4' => 'mucosas muito hipocoradas',
            'icteric' => 'mucosas ictéricas (amareladas)',
            'cyanotic' => 'mucosas cianóticas (arroxeadas)',
            'congested' => 'mucosas congestas',
        ],
        'cardiovascular' => [
            'murmur_1' => 'sopro cardíaco leve',
            'murmur_2' => 'sopro cardíaco',
            'murmur_3' => 'sopro cardíaco moderado',
            'murmur_4' => 'sopro cardíaco moderado a intenso',
            'murmur_5' => 'sopro cardíaco intenso',
            'murmur_6' => 'sopro cardíaco muito intenso',
            'arrhythmia' => 'arritmia cardíaca',
            'muffled_sounds' => 'bulhas cardíacas abafadas',
        ],
        'respiratory' => [
            'tachypneic' => 'respiração acelerada',
            'dyspneic' => 'dificuldade respiratória',
            'crackles' => 'estertores pulmonares',
            'wheezing' => 'sibilos pulmonares',
        ],
        'digestive' => [
            'painful' => 'dor à palpação abdominal',
            'distended' => 'abdômen distendido',
            'palpable_mass' => 'massa abdominal palpável',
        ],
        'integumentary' => [
            'alopecia' => 'perda de pelo',
            'pruritus' => 'coceira na pele',
            'lesion' => 'lesão de pele',
            'ectoparasites' => 'presença de pulgas ou carrapatos',
        ],
        'urinary' => [
            'dysuria' => 'dificuldade para urinar',
            'hematuria' => 'sangue na urina',
            'polyuria_polydipsia' => 'aumento de sede e urina',
        ],
        'neurological' => [
            'ataxia' => 'falta de coordenação',
            'seizure' => 'convulsão',
            'altered_consciousness' => 'nível de consciência alterado',
        ],
        'lymph_nodes' => ['enlarged' => 'linfonodos aumentados'],
        'oral_dental' => [
            'tartar_1' => 'tártaro leve',
            'tartar_2' => 'tártaro moderado',
            'tartar_3' => 'tártaro acentuado',
            'tartar_4' => 'tártaro intenso',
            'gingivitis' => 'gengivite',
        ],
        'eyes_ears' => [
            'ocular_discharge_right' => 'secreção no olho direito',
            'ocular_discharge_left' => 'secreção no olho esquerdo',
            'ocular_discharge_bilateral' => 'secreção nos dois olhos',
            'aural_discharge_right' => 'secreção no ouvido direito',
            'aural_discharge_left' => 'secreção no ouvido esquerdo',
            'aural_discharge_bilateral' => 'secreção nos dois ouvidos',
        ],
        'hydration_status' => [
            'mild' => 'hidratação levemente reduzida',
            'moderate' => 'hidratação moderadamente reduzida',
            'severe' => 'hidratação bastante reduzida',
        ],
        'capillary_refill_time' => [
            '2_3s' => 'tempo de perfusão capilar levemente aumentado',
            'gt_3s' => 'tempo de perfusão capilar aumentado',
        ],
    ],

    /** Valor(es) considerado(s) "nada a relatar" por sistema — o resto vira achado no resumo. */
    'physical_exam_baseline' => [
        'general_state' => ['alert'],
        'mucous_membranes' => ['normal'],
        'cardiovascular' => ['normal'],
        'respiratory' => ['eupneic'],
        'digestive' => ['normal'],
        'integumentary' => ['normal'],
        'urinary' => ['normal'],
        'neurological' => ['normal'],
        'lymph_nodes' => ['normal'],
        'oral_dental' => ['normal', 'tartar_0'],
        'eyes_ears' => ['normal'],
    ],

    'diagnosis_status' => [
        'presumptive' => 'presuntivo',
        'definitive' => 'definitivo',
        'differential' => 'diagnóstico diferencial',
        'ruled_out' => 'descartado',
    ],

    'treatment_actions' => [
        'exams_requested' => [
            'cbc' => 'solicitação de hemograma',
            'biochemistry_renal' => 'solicitação de exame de função renal',
            'biochemistry_hepatic' => 'solicitação de exame de função hepática',
            'urinalysis' => 'solicitação de exame de urina',
            'fecal_parasitology' => 'solicitação de exame de fezes',
            'xray' => 'solicitação de raio-x',
            'ultrasound' => 'solicitação de ultrassom',
            'infectious_serology' => 'solicitação de sorologia',
            'biopsy' => 'solicitação de biópsia',
            'cytology' => 'solicitação de citologia',
        ],
        'medication_type' => [
            'antibiotic' => 'prescrição de antibiótico',
            'nsaid_or_analgesic' => 'prescrição de anti-inflamatório ou analgésico',
            'antiemetic' => 'prescrição de medicação para o estômago',
            'gastroprotective' => 'prescrição de protetor gástrico',
            'corticosteroid' => 'prescrição de corticoide',
            'antiparasitic' => 'prescrição de antiparasitário',
        ],
        'procedures' => [
            'wound_dressing' => 'realização de curativo',
            'suture' => 'sutura',
            'drainage' => 'drenagem',
            'dental_extraction' => 'extração dentária',
            'medicated_bath' => 'banho medicamentoso',
            'vaccine_application' => 'aplicação de vacina',
            'deworming_application' => 'aplicação de vermífugo',
            'anal_gland_expression' => 'expressão das glândulas anais',
            'blood_or_urine_collection' => 'coleta de sangue ou urina',
            'sedation_for_procedure' => 'sedação para procedimento',
        ],
        'management_diet' => [
            'hypoallergenic_diet' => 'dieta hipoalergênica',
            'renal_diet' => 'dieta renal',
            'gastrointestinal_diet' => 'dieta gastrointestinal por alguns dias',
            'water_restriction' => 'restrição de água',
            'exercise_restriction' => 'repouso',
            'weight_control' => 'controle de peso',
            'gradual_diet_transition' => 'transição gradual de alimentação',
        ],
        'tutor_guidance' => [
            'monitor_at_home' => 'observação em casa',
            'return_if_worsening' => 'retorno se piorar',
            'no_bath_for_days' => 'evitar banho por alguns dias',
            'elizabethan_collar' => 'uso de colar elisabetano',
            'isolate_from_other_animals' => 'isolamento de outros animais',
            'calm_quiet_environment' => 'ambiente calmo e tranquilo',
        ],
        'referral' => [
            'dermatology' => 'encaminhamento para dermatologia',
            'cardiology' => 'encaminhamento para cardiologia',
            'ophthalmology' => 'encaminhamento para oftalmologia',
            'oncology' => 'encaminhamento para oncologia',
            'orthopedics' => 'encaminhamento para ortopedia',
            'neurology' => 'encaminhamento para neurologia',
        ],
    ],
];
