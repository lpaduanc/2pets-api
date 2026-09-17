<?php

namespace App\DataTransferObjects;

use App\Models\Exam;
use App\Models\MedicalRecord;

/**
 * Resultado de `MedicalRecordEncounterResolver::resolve()` — exatamente um dos dois
 * artefatos vem preenchido, nunca os dois (contrato
 * docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §3): clínico gera
 * `MedicalRecord`, exame gera `Exam`, não clínico não gera nada.
 */
final readonly class EncounterResolution
{
    private function __construct(
        public ?MedicalRecord $medicalRecord,
        public ?Exam $exam,
    ) {}

    public static function forMedicalRecord(MedicalRecord $medicalRecord): self
    {
        return new self($medicalRecord, null);
    }

    public static function forExam(Exam $exam): self
    {
        return new self(null, $exam);
    }

    public static function none(): self
    {
        return new self(null, null);
    }
}
