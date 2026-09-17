<?php

/**
 * Lista curada de alertas clínicos do receituário — Apêndice A de
 * docs/atendimento-veterinario/02-receituario-dominio.md, no formato do contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §5.
 *
 * Servida por `GET /api/reference/prescription-alerts` (`PrescriptionAlertsController`).
 * O MATCH contra o que o vet está digitando é calculado no FRONTEND (contrato §5: "o
 * frontend busca uma vez, cacheia na store, e calcula o match localmente") — este arquivo só
 * é o dado de referência.
 *
 * `substance`/`aliases`/`condition_terms` já vêm normalizados aqui (minúsculas, sem acento),
 * exatamente como o Apêndice A.2 pede ("normalize os dois lados antes de comparar"): quem
 * compara só precisa normalizar o OUTRO lado (o que o vet digitou, `Pet.chronic_diseases`,
 * `Pet.allergies`) do mesmo jeito para o match funcionar. O texto acentuado e com a
 * pontuação certa para exibição mora em `message`, nunca nas chaves de comparação.
 *
 * Regra de tom: todo alerta PEDE verificação, nunca afirma erro — o match é por texto livre,
 * então falso positivo é esperado. `severity` só tem `high`/`medium` nesta lista.
 *
 * NÃO adicionar par sem toxicologia/farmacologia consolidada e não-controversa — a régua é
 * "eu assinaria isso", não uma meta de quantidade (por isso 7 entradas, não 15). Fora desta
 * lista, por decisão documentada no Apêndice A, não por esquecimento:
 *   - sensibilidade MDR1 à ivermectina em Collies e raças aparentadas — é risco POR RAÇA, e
 *     este schema só tem granularidade de espécie; implementar pela metade daria alerta em
 *     todo cão e falsa sensação de cobertura justamente para quem precisa;
 *   - xilitol, chocolate/teobromina e outras toxinas alimentares — não são substâncias que
 *     se prescreve, são domínio de triagem de intoxicação acidental.
 */

return [

    'species_substance' => [
        [
            'substance' => 'paracetamol',
            'aliases' => ['acetaminofeno', 'tylenol'],
            'species' => ['cat'],
            'severity' => 'high',
            'message_key' => 'species_substance.paracetamol_cat',
            'message' => 'Paracetamol (acetaminofeno) está entre as intoxicações mais graves e mais evitáveis em gatos — a espécie tem baixa capacidade de metabolizar a substância, e mesmo doses pequenas podem ser fatais. Verifique se este item deveria mesmo ser prescrito para um gato antes de confirmar.',
        ],
        [
            'substance' => 'ibuprofeno',
            'aliases' => ['naproxeno', 'diclofenaco', 'advil', 'alivium', 'flanax', 'cataflam', 'voltaren'],
            'species' => ['dog', 'cat'],
            'severity' => 'high',
            'message_key' => 'species_substance.human_nsaid',
            'message' => 'Anti-inflamatórios de uso humano (ibuprofeno, naproxeno, diclofenaco) têm margem de segurança estreita em cães e são ainda mais perigosos em gatos — não são a mesma coisa que um AINE de formulação veterinária (ex.: meloxicam, carprofeno). Verifique se a intenção não era um AINE veterinário.',
        ],
        [
            'substance' => 'permetrina',
            'aliases' => ['piretrina', 'piretroide'],
            'species' => ['cat'],
            'severity' => 'high',
            'message_key' => 'species_substance.permethrin_cat',
            'message' => 'Permetrina e outros piretroides em produtos antipulgas tópicos formulados para cão são uma das intoxicações mais comuns e mais graves em gato. Verifique se o produto é realmente formulado e aprovado para uso felino.',
        ],
        [
            'substance' => 'acido acetilsalicilico',
            'aliases' => ['aspirina', 'aas'],
            'species' => ['cat'],
            'severity' => 'medium',
            'message_key' => 'species_substance.aspirin_cat',
            'message' => 'Gatos metabolizam o ácido acetilsalicílico muito mais lentamente que cães, e a dose e o intervalo seguros são bem diferentes entre as espécies. Verifique se a dose e a frequência foram ajustadas especificamente para gato.',
        ],
    ],

    'chronic_condition_substance' => [
        [
            'substance' => 'anti-inflamatorio nao esteroidal (aine)',
            'aliases' => [
                'meloxicam', 'carprofeno', 'firocoxibe', 'cetoprofeno', 'robenacoxibe',
                'acido tolfenamico', 'deracoxibe', 'etodolaco', 'ibuprofeno', 'naproxeno', 'diclofenaco',
            ],
            'condition_terms' => [
                'doenca renal cronica', 'insuficiencia renal cronica', 'irc', 'doenca renal',
                'insuficiencia renal', 'nefropatia cronica', 'drc',
            ],
            'severity' => 'high',
            'message_key' => 'chronic_condition_substance.nsaid_ckd',
            'message' => 'Anti-inflamatórios não esteroidais podem reduzir ainda mais a função renal em paciente com doença renal crônica. O cadastro deste pet menciona uma condição renal — verifique se este é o AINE e a dose certos para este caso.',
        ],
        [
            'substance' => 'corticoide sistemico (glicocorticoide)',
            'aliases' => ['prednisona', 'prednisolona', 'dexametasona', 'metilprednisolona', 'triancinolona'],
            'condition_terms' => ['diabetes', 'diabetes mellitus', 'dm', 'diabetico', 'diabetica'],
            'severity' => 'medium',
            'message_key' => 'chronic_condition_substance.corticosteroid_diabetes',
            'message' => 'Corticoides sistêmicos podem elevar a glicemia e dificultar o controle de um paciente diabético. O cadastro deste pet menciona diabetes — verifique se a dose considera esse quadro e se o acompanhamento glicêmico será ajustado.',
        ],
        [
            'substance' => 'aminoglicosideo',
            'aliases' => ['gentamicina', 'amicacina', 'tobramicina', 'neomicina'],
            'condition_terms' => [
                'doenca renal cronica', 'insuficiencia renal cronica', 'irc', 'doenca renal',
                'insuficiencia renal', 'nefropatia cronica', 'drc',
            ],
            'severity' => 'medium',
            'message_key' => 'chronic_condition_substance.aminoglycoside_ckd',
            'message' => 'Aminoglicosídeos são nefrotóxicos e exigem cautela redobrada em paciente com doença renal. O cadastro deste pet menciona uma condição renal — verifique a necessidade, a dose e o monitoramento antes de confirmar.',
        ],
    ],

];
