<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um item de `Prescription` — contrato docs/atendimento-veterinario/03-contrato-receituario.md §2.
 *
 * @mixin \App\Models\PrescriptionItem
 */
class PrescriptionItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'product_id' => $this->product_id,
            'active_ingredient' => $this->active_ingredient,
            'commercial_name' => $this->commercial_name,
            'concentration' => $this->concentration,
            'pharmaceutical_form' => $this->pharmaceutical_form?->value,
            'form_notes' => $this->form_notes,
            'route' => $this->route?->value,
            'route_notes' => $this->route_notes,
            'dose_value' => $this->dose_value !== null ? (float) $this->dose_value : null,
            'dose_unit' => $this->dose_unit,
            'dose_per_kg' => $this->dose_per_kg !== null ? (float) $this->dose_per_kg : null,
            'dose_calculated' => (bool) $this->dose_calculated,
            'frequency' => $this->frequency?->value,
            'frequency_custom_hours' => $this->frequency_custom_hours,
            'frequency_notes' => $this->frequency_notes,
            'duration_text' => $this->duration_text,
            'is_continuous_use' => (bool) $this->is_continuous_use,
            'quantity_to_dispense' => $this->quantity_to_dispense,
            'instructions_for_tutor' => $this->instructions_for_tutor,
            'is_controlled' => (bool) $this->is_controlled,
        ];
    }
}
