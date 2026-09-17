<?php

/**
 * Taxonomia clínica canônica do fluxo de atendimento veterinário.
 *
 * Fonte única para a validação dos Form Requests (`Rule::in(...)`). Os slugs abaixo são
 * FIXADOS por docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §5 — o frontend
 * guarda os rótulos em português para o mesmo conjunto de valores
 * (`src/constants/clinical-parameters.js`). Nenhum dos dois lados inventa slug novo sem
 * atualizar o contrato primeiro.
 *
 * Decisão de modelagem: config em vez de Enum PHP. Ao contrário de `MedicalRecordStatus`
 * (que carrega comportamento — `isDraft()`/`isFinalized()` usados por Policy e Service),
 * estes valores são taxonomia pura, sem lógica associada além de "é um valor válido para
 * este campo". Um Enum por sistema do exame físico (11 chaves) mais `chief_complaint` mais
 * as duas colunas próprias somaria 14+ classes só para `in_array`, sem ganho de
 * comportamento — a estrutura aninhada de um único arquivo espelha o formato JSON do
 * contrato (§5.1) e fica mais fácil de auditar contra o documento lado a lado.
 */

return [

    /**
     * `medical_records.physical_exam` — cada chave é um sistema, cada valor a lista de
     * opções aceitas para aquele sistema. Contrato §5.1/§5.2.
     */
    'physical_exam_systems' => [
        'general_state' => ['alert', 'apathetic', 'prostrate', 'comatose'],
        'mucous_membranes' => [
            'normal', 'pale', 'hypochromic_1', 'hypochromic_2', 'hypochromic_3', 'hypochromic_4',
            'icteric', 'cyanotic', 'congested',
        ],
        'cardiovascular' => [
            'normal', 'murmur_1', 'murmur_2', 'murmur_3', 'murmur_4', 'murmur_5', 'murmur_6',
            'arrhythmia', 'muffled_sounds',
        ],
        'respiratory' => ['eupneic', 'tachypneic', 'dyspneic', 'crackles', 'wheezing'],
        'digestive' => ['normal', 'painful', 'distended', 'palpable_mass'],
        'integumentary' => ['normal', 'alopecia', 'pruritus', 'lesion', 'ectoparasites'],
        'urinary' => ['normal', 'dysuria', 'hematuria', 'polyuria_polydipsia'],
        'neurological' => ['normal', 'ataxia', 'seizure', 'altered_consciousness'],
        'lymph_nodes' => ['normal', 'enlarged'],
        'oral_dental' => ['normal', 'tartar_0', 'tartar_1', 'tartar_2', 'tartar_3', 'tartar_4', 'gingivitis'],
        'eyes_ears' => [
            'normal',
            'ocular_discharge_right', 'ocular_discharge_left', 'ocular_discharge_bilateral',
            'aural_discharge_right', 'aural_discharge_left', 'aural_discharge_bilateral',
        ],
    ],

    /** Coluna própria (fora do JSON) — contrato §2/§5.2. */
    'capillary_refill_time' => ['lt_2s', '2_3s', 'gt_3s'],

    /** Coluna própria (fora do JSON) — contrato §2/§5.2. */
    'hydration_status' => ['normal', 'mild', 'moderate', 'severe'],

    /**
     * `chief_complaint` — comum a cão e gato, mais as extensões por espécie. A validação
     * (`ValidChiefComplaint`) resolve a espécie do pet e aceita comuns + extensão da espécie
     * + `other`; este array combinado é o que o CHECK constraint do banco aceita (o banco não
     * sabe a espécie do pet).
     *
     * Erratum de docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §5.4, fixado por
     * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §10: `weight_change`
     * e `behavior_change` estavam marcados como exclusivos de cão sem razão clínica (perda de
     * peso é sinal clássico de hipertireoidismo felino, já no catálogo `Pathology`) — passam a
     * comuns às duas espécies. Mudança de metadado de espécie, não de slug.
     */
    'chief_complaint' => [
        'common' => [
            'vomiting', 'diarrhea', 'anorexia', 'lethargy', 'pruritus', 'lameness',
            'cough_sneeze', 'ocular_discharge', 'aural_discharge', 'dysuria', 'skin_wound',
            'routine_checkup', 'revaccination', 'elective_neutering', 'pre_anesthetic_evaluation',
            'post_surgical_followup', 'weight_change', 'behavior_change', 'other',
        ],
        'dog' => ['bad_breath_dental'],
        'cat' => ['litter_box_change', 'hairball', 'respiratory_distress'],
    ],

    /** Regra única de finalização (contrato §3): nenhum outro campo é obrigatório. */
    'body_condition_score' => ['min' => 1, 'max' => 9],
    'pain_score' => ['min' => 0, 'max' => 4],

    /**
     * `medical_records.anamnesis_signs` — contrato
     * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §3. 43 sinais em 9
     * grupos (o grupo é só organização de UI, não é validado aqui — o backend valida contra a
     * lista achatada `valid_signs`).
     *
     * Vários slugs são DELIBERADAMENTE os mesmos de `chief_complaint` acima — é a mesma coisa
     * dita em lugares diferentes, não um sinônimo novo.
     */
    'anamnesis_signs' => [
        'valid_signs' => [
            // digestive
            'vomiting', 'diarrhea', 'anorexia', 'constipation', 'abdominal_distension', 'excessive_drooling',
            // respiratory
            'cough_sneeze', 'nasal_discharge', 'noisy_breathing', 'respiratory_distress',
            // integumentary
            'pruritus', 'hair_loss', 'skin_odor', 'lump_or_mass', 'skin_wound',
            // musculoskeletal
            'lameness', 'difficulty_rising', 'tremors', 'pain_on_touch',
            // neurological
            'seizure', 'disorientation', 'head_tilt', 'loss_of_balance',
            // ocular
            'ocular_discharge', 'red_eye', 'excessive_blinking', 'cloudy_eye',
            // aural
            'aural_discharge', 'head_shaking', 'ear_odor',
            // urinary
            'dysuria', 'hematuria', 'inappropriate_elimination', 'polyuria', 'oliguria',
            // general / espécie
            'lethargy', 'weight_change', 'perceived_fever', 'swollen_lymph_nodes', 'behavior_change',
            'bad_breath_dental', 'litter_box_change', 'hairball',
        ],

        /** Modificadores universais (§3.2) — toda chave é opcional em cada item. */
        'onset' => ['lt_24h', '1_3_days', '4_7_days', '1_4_weeks', 'gt_1_month', 'chronic_recurrent'],
        'evolution' => ['worsening', 'stable', 'improving', 'intermittent'],

        /** Modificador secundário universal — vale para qualquer sinal. */
        'intensity' => ['mild', 'moderate', 'severe'],

        /** `frequency_per_day` só é aceito para estes 4 sinais episódicos (§3.2). */
        'frequency_signs' => ['vomiting', 'diarrhea', 'cough_sneeze', 'seizure'],

        /**
         * Modificadores específicos: só o sinal-chave aceita o campo correspondente
         * (§3.2 "Específicos"). `ValidAnamnesisSigns` usa este mapa para rejeitar, por
         * exemplo, `limb` num item de `vomiting`.
         */
        'specific_modifiers' => [
            'vomiting' => ['field' => 'content', 'values' => ['food', 'bile', 'blood', 'foam']],
            'diarrhea' => ['field' => 'consistency', 'values' => ['pasty', 'liquid', 'with_fresh_blood', 'with_dark_blood', 'with_mucus']],
            'lameness' => ['field' => 'limb', 'values' => ['front_right', 'front_left', 'rear_right', 'rear_left', 'multiple']],
            'ocular_discharge' => ['field' => 'laterality', 'values' => ['right', 'left', 'bilateral']],
            'aural_discharge' => ['field' => 'laterality', 'values' => ['right', 'left', 'bilateral']],
        ],
    ],

    /**
     * `medical_records.behavior_findings` — objeto de 6 chaves fixas, select único cada
     * (contrato §4). Chave ausente = não perguntado; presente com `normal` = perguntado e sem
     * alteração — a UI decide como distinguir, o backend só valida o valor.
     */
    'behavior_findings' => [
        'appetite' => ['normal', 'reduced', 'increased', 'absent'],
        'water_intake' => ['normal', 'increased', 'reduced'],
        'activity_level' => ['normal', 'reduced', 'increased'],
        'sleep_pattern' => ['normal', 'increased', 'restless'],
        'social_interaction' => ['normal', 'withdrawn', 'aggressive', 'clingy'],
        'vocalization' => ['normal', 'increased', 'unusual_sounds', 'absent'],
    ],

    /**
     * `medical_records.context_flags` — objeto (contrato §5): `street_access` é select,
     * os outros 5 são par booleano + nota (`{chave}_notes`).
     */
    'context_flags' => [
        'street_access' => ['none', 'supervised', 'free_access'],
        'boolean_flags' => [
            'contact_with_other_animals',
            'possible_foreign_body_or_toxin',
            'recent_travel',
            'recent_diet_change',
            'self_medicated_by_tutor',
        ],
    ],

    /**
     * `medical_records.treatment_actions` — array de `{category, item, notes}` (contrato §7).
     * `other` sempre libera `notes`; nenhuma categoria é obrigatória para finalizar.
     */
    'treatment_actions' => [
        'exams_requested' => [
            'cbc', 'biochemistry_renal', 'biochemistry_hepatic', 'urinalysis', 'fecal_parasitology',
            'xray', 'ultrasound', 'infectious_serology', 'biopsy', 'cytology', 'other',
        ],
        'medication_type' => [
            'antibiotic', 'nsaid_or_analgesic', 'antiemetic', 'gastroprotective', 'corticosteroid',
            'antiparasitic', 'other',
        ],
        'procedures' => [
            'wound_dressing', 'suture', 'drainage', 'dental_extraction', 'medicated_bath',
            'vaccine_application', 'deworming_application', 'anal_gland_expression',
            'blood_or_urine_collection', 'sedation_for_procedure', 'other',
        ],
        'management_diet' => [
            'hypoallergenic_diet', 'renal_diet', 'gastrointestinal_diet', 'water_restriction',
            'exercise_restriction', 'weight_control', 'gradual_diet_transition', 'other',
        ],
        'tutor_guidance' => [
            'monitor_at_home', 'return_if_worsening', 'no_bath_for_days', 'elizabethan_collar',
            'isolate_from_other_animals', 'calm_quiet_environment', 'other',
        ],
        'referral' => [
            'dermatology', 'cardiology', 'ophthalmology', 'oncology', 'orthopedics', 'neurology', 'other',
        ],
    ],

    /** `medical_records.diagnosis_status` — contrato §8. Select ao lado do texto de diagnóstico. */
    'diagnosis_status' => ['presumptive', 'definitive', 'differential', 'ruled_out'],
];
