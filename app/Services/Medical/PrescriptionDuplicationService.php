<?php

namespace App\Services\Medical;

use App\Enums\PrescriptionKind;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Support\Facades\DB;

/**
 * "Duplicar prescrição" — alternativa barata ao modelo de prescrição nomeado (V2, fora de
 * escopo — contrato docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md, seção
 * "Fora de escopo"). Clona `prescription_items` para uma nova `Prescription` RASCUNHO
 * (nunca emitida), preservando a original intacta.
 */
final class PrescriptionDuplicationService
{
    public function duplicate(Prescription $source): Prescription
    {
        return DB::transaction(function () use ($source): Prescription {
            $source->loadMissing('items');

            $duplicate = Prescription::create([
                'pet_id' => $source->pet_id,
                'professional_id' => $source->professional_id,
                'appointment_id' => $source->appointment_id,
                'medical_record_id' => $source->medical_record_id,
                'prescription_date' => now()->toDateString(),
                'general_instructions' => $source->general_instructions,
                'warnings' => $source->warnings,
                'is_controlled' => $source->is_controlled,
                'standalone_reason' => $source->standalone_reason,
                'kind' => $source->kind?->value ?? PrescriptionKind::SIMPLE->value,
            ]);

            foreach ($source->items as $index => $item) {
                $duplicate->items()->create($this->cloneItemAttributes($item, $index));
            }

            return $duplicate;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function cloneItemAttributes(PrescriptionItem $item, int $index): array
    {
        return [
            'position' => $index + 1,
            'product_id' => $item->product_id,
            'active_ingredient' => $item->active_ingredient,
            'commercial_name' => $item->commercial_name,
            'concentration' => $item->concentration,
            'pharmaceutical_form' => $item->pharmaceutical_form?->value,
            'form_notes' => $item->form_notes,
            'route' => $item->route?->value,
            'route_notes' => $item->route_notes,
            'dose_value' => $item->dose_value,
            'dose_unit' => $item->dose_unit,
            'dose_per_kg' => $item->dose_per_kg,
            'dose_calculated' => $item->dose_calculated,
            'frequency' => $item->frequency?->value,
            'frequency_custom_hours' => $item->frequency_custom_hours,
            'frequency_notes' => $item->frequency_notes,
            'duration_text' => $item->duration_text,
            'is_continuous_use' => $item->is_continuous_use,
            'quantity_to_dispense' => $item->quantity_to_dispense,
            'instructions_for_tutor' => $item->instructions_for_tutor,
            'is_controlled' => $item->is_controlled,
            // Regra de negócio da spec 12 ("duplicar prescrição"): `starts_at` é
            // RECALCULADO para agora, nunca copiado da prescrição original.
            'starts_at' => $item->starts_at !== null ? now() : null,
        ];
    }
}
