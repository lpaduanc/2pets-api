<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ImmunizationProtocolDose
 */
class ImmunizationProtocolDoseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dose_number' => $this->dose_number,
            'interval_days' => $this->interval_days,
            'depends_on_dose_id' => $this->depends_on_dose_id,
            'anchor' => $this->anchor?->value,
            'transitions_to_product_id' => $this->transitions_to_product_id,
            'min_age_days' => $this->min_age_days,
            'max_age_days' => $this->max_age_days,
            'notes' => $this->notes,
        ];
    }
}
