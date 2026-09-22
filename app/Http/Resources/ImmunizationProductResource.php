<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ImmunizationProduct
 */
class ImmunizationProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'group' => $this->group?->value,
            'group_label' => $this->group?->label(),
            'manufacturer' => $this->manufacturer,
            'description' => $this->description,
            'legally_required' => (bool) $this->legally_required,
            'active' => (bool) $this->active,
            'species' => $this->whenLoaded(
                'speciesLinks',
                fn () => $this->speciesLinks->pluck('species')->map(fn ($species) => $species->value)->values(),
            ),
        ];
    }
}
