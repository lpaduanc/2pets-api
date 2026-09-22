<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PetImmunizationDose
 */
class PetImmunizationDoseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'protocol_dose_id' => $this->protocol_dose_id,
            'dose_number' => $this->whenLoaded('protocolDose', fn () => $this->protocolDose->dose_number),
            'scheduled_for' => $this->scheduled_for->toDateString(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'applied_by' => $this->applied_by,
            'vaccination_id' => $this->vaccination_id,
            'skipped' => (bool) $this->skipped,
            'status' => $this->statusLabel(),
        ];
    }
}
