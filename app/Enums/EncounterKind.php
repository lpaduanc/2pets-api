<?php

namespace App\Enums;

/**
 * Resultado da classificação de um agendamento em `MedicalRecordEncounterResolver` —
 * contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §3
 * ("encounter_kind, calculado, não mais escolhido").
 */
enum EncounterKind: string
{
    /** Gera/reabre `MedicalRecord` — exige peso + (diagnóstico OU plano) para finalizar. */
    case CLINICAL = 'clinical';

    /** Gera/reabre `Exam` — fecha com `exam_type`+`exam_date`, nunca com diagnóstico. */
    case EXAM = 'exam';

    /** Nenhum artefato clínico nasce; o agendamento fecha e a fatura lança normalmente. */
    case NON_CLINICAL = 'non_clinical';
}
