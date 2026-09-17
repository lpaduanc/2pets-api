<?php

namespace App\Http\Resources;

use App\Support\PetAgeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight resource for pet listings (index endpoint).
 * Only includes fields needed for cards/lists.
 * For full details, see PetDetailResource.
 */
class PetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'species' => $this->species,
            'breed' => $this->breed,
            'breed_id' => $this->breed_id,
            'gender' => $this->gender,

            // Age calculation
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'age' => $this->birth_date ? $this->calculateAge() : null,

            // Physical characteristics
            'weight' => $this->weight ? (float) $this->weight : null,
            'size' => $this->size,
            'color' => $this->color,
            'coat_colors' => $this->coat_colors,

            // Health basics
            'neutered' => $this->neutered,
            'neutered_status' => $this->neutered_status,
            'microchip_number' => $this->microchip_number,

            // Media
            'image' => $this->image_url,

            // Lost pet
            'is_lost' => $this->is_lost ?? false,
            'lost_since' => $this->when($this->is_lost, $this->lost_since?->toISOString()),

            // Owner
            'user_id' => $this->user_id,

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Calculate a human-friendly age string.
     */
    protected function calculateAge(): array
    {
        return PetAgeCalculator::calculate($this->birth_date);
    }
}
