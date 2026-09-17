<?php

namespace App\Enums;

/**
 * Estado do ciclo de vida de um prontuário (`medical_records.status`).
 *
 * Ao contrário de `AppointmentStatus`, não existe máquina de transições aqui: `draft` só
 * vira `finalized` (via `MedicalRecordFinalizationService::finalize()`), e `finalized` é
 * terminal — correção depois é `MedicalRecordAddendum`, nunca reescrita nem volta a `draft`.
 * Ver docs/atendimento-veterinario/00-dominio-e-escopo.md §1.5.
 */
enum MedicalRecordStatus: string
{
    case DRAFT = 'draft';
    case FINALIZED = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::FINALIZED => 'Finalizado',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this === self::FINALIZED;
    }
}
