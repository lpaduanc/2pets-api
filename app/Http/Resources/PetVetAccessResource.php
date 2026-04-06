<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PetVetAccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'pet' => new PetResource($this->whenLoaded('pet')),
            'veterinarian_id' => $this->veterinarian_id,
            'veterinarian' => new UserResource($this->whenLoaded('veterinarian')),
            'granted_by' => $this->granted_by,
            'grantor' => new UserResource($this->whenLoaded('grantor')),
            'access_level' => $this->access_level,
            'granted_at' => $this->granted_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
