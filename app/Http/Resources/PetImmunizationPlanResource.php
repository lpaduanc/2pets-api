<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PetImmunizationPlan
 */
class PetImmunizationPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'protocol' => new ImmunizationProtocolResource($this->whenLoaded('protocol')),
            'started_at' => $this->started_at->toDateString(),
            'status' => $this->status?->value,
            'doses' => PetImmunizationDoseResource::collection($this->whenLoaded('doses')),
        ];
    }
}
