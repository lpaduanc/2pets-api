<?php

namespace App\Services\Pet;

use App\Models\PetWeightHistory;
use App\Models\User;
use Carbon\Carbon;

/**
 * Ponto único de escrita de `PetWeightHistory` a partir de um registro clínico — extraído de
 * `MedicalRecordFinalizationService` para ser reaproveitado por
 * `HospitalizationProgressNoteService` (contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1.1/§7) sem duplicar a mesma
 * criação em dois lugares.
 */
final class PetWeightHistoryWriter
{
    /**
     * `$weight` aceita o valor cru de um cast `decimal:2` (`string|null`) — mesmo formato que
     * `MedicalRecord::$weight` e `HospitalizationProgressNote::$weight` já devolvem.
     */
    public function recordIfPresent(int $petId, User $measuredBy, string|float|null $weight, Carbon $measuredAt): void
    {
        if ($weight === null) {
            return;
        }

        PetWeightHistory::create([
            'pet_id' => $petId,
            'measured_by_user_id' => $measuredBy->id,
            'weight' => $weight,
            'measured_at' => $measuredAt,
        ]);
    }
}
