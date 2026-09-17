<?php

namespace App\Services\Medical;

use App\Models\PetMedication;
use App\Models\Prescription;
use App\Models\PrescriptionItem;

/**
 * Promove um item de prescrição a `PetMedication` (lista de uso contínuo do pet) — doc de
 * domínio docs/atendimento-veterinario/02-receituario-dominio.md §5.2. SEMPRE por ação
 * explícita do tutor (`PrescriptionItemPromotionController`), nunca automático: virar
 * "medicação atual" sem revisão é dado sujo que qualquer vet futuro com `PetVetAccess` lê
 * como verdade.
 */
final class PrescriptionMedicationPromotionService
{
    public function promote(PrescriptionItem $item, Prescription $prescription): PetMedication
    {
        return PetMedication::create([
            'pet_id' => $prescription->pet_id,
            'name' => $item->displayName(),
            'dosage' => $this->dosageSummary($item),
            'frequency' => $item->frequencyLabel(),
            'start_date' => $prescription->issued_at?->toDateString() ?? now()->toDateString(),
            'end_date' => null,
            'prescribed_by' => $prescription->professional_id,
            'notes' => $item->instructions_for_tutor,
            'active' => true,
        ]);
    }

    private function dosageSummary(PrescriptionItem $item): ?string
    {
        if ($item->dose_value !== null && $item->dose_unit !== null) {
            return "{$item->dose_value} {$item->dose_unit}";
        }

        return $item->concentration;
    }
}
