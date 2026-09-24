<?php

namespace App\Http\Resources;

use App\Models\UserSearchLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserSearchLocation
 */
class SearchLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'source' => $this->source,
            'label' => $this->label,
            'zip_code' => $this->zip_code,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
