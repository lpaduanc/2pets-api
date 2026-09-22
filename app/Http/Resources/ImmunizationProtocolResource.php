<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ImmunizationProtocol
 */
class ImmunizationProtocolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'immunization_product_id' => $this->immunization_product_id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'application_mode' => $this->application_mode?->value,
            'total_doses' => $this->total_doses,
            'active' => (bool) $this->active,
            'doses' => ImmunizationProtocolDoseResource::collection($this->whenLoaded('doses')),
        ];
    }
}
