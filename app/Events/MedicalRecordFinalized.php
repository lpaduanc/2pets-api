<?php

namespace App\Events;

use App\Models\MedicalRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado por `MedicalRecordFinalizationService::finalize()`. É o evento certo para o
 * convite de avaliação (item 12 do MVP, docs/atendimento-veterinario/00-dominio-e-escopo.md
 * §6 e diferencial #6): só quem teve um atendimento clínico REAL e documentado é convidado a
 * avaliar, ao contrário de disparar no simples `AppointmentStatus::COMPLETED`.
 */
class MedicalRecordFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MedicalRecord $medicalRecord
    ) {}
}
