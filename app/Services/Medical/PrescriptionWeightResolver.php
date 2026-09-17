<?php

namespace App\Services\Medical;

use App\Models\PetWeightHistory;
use App\Models\Prescription;

/**
 * Peso do pet para o cálculo de dose por kg — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §3.
 *
 * Regra literal do contrato: vinculada a um atendimento usa SÓ `medical_record.weight` (sem
 * fallback para o histórico); standalone usa o registro mais recente de `PetWeightHistory`.
 * Peso desconhecido devolve `null` — nunca lança exceção, porque o cálculo de dose nunca pode
 * travar a prescrição.
 */
final class PrescriptionWeightResolver
{
    public function resolve(Prescription $prescription): ?float
    {
        if (! $prescription->isStandalone()) {
            $weight = $prescription->loadMissing('medicalRecord')->medicalRecord?->weight;

            return $weight !== null ? (float) $weight : null;
        }

        return $this->latestWeightFromHistory($prescription->pet_id);
    }

    private function latestWeightFromHistory(int $petId): ?float
    {
        $weight = PetWeightHistory::where('pet_id', $petId)->latestFirst()->value('weight');

        return $weight !== null ? (float) $weight : null;
    }
}
