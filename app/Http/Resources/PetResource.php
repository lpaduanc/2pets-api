<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'gender' => $this->gender,

            // Age calculation
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'age' => $this->birth_date ? $this->calculateAge() : null,

            // Physical characteristics
            'weight' => $this->weight ? (float) $this->weight : null,
            'color' => $this->color,

            // Health basics
            'neutered' => $this->neutered,
            'blood_type' => $this->blood_type,
            'allergies' => $this->allergies,
            'has_allergies' => !empty($this->allergies),
            'chronic_diseases' => $this->chronic_diseases,
            'current_medications' => $this->current_medications,

            // Behavior
            'temperament' => $this->temperament,
            'behavior_notes' => $this->behavior_notes,
            'social_with' => $this->social_with,

            // Media
            'image' => $this->image_url,

            // Lost pet
            'is_lost' => $this->is_lost ?? false,
            'lost_alert_message' => $this->when($this->is_lost, $this->lost_alert_message),
            'lost_since' => $this->when($this->is_lost, $this->lost_since?->toISOString()),

            // Notes
            'notes' => $this->notes,

            // Vaccination status
            'vaccines_up_to_date' => $this->whenLoaded('vaccinations', function () {
                $overdue = $this->vaccinations->filter(fn ($v) => $v->next_dose_date && $v->next_dose_date < now());
                return $overdue->isEmpty();
            }, true),

            // Related data
            'vaccinations' => $this->whenLoaded('vaccinations'),
            'dewormings' => $this->whenLoaded('dewormings'),
            'medications' => $this->whenLoaded('medications'),
            'weight_history' => $this->whenLoaded('weightHistory'),
            'vet_accesses' => $this->whenLoaded('vetAccesses'),

            // Owner
            'user_id' => $this->user_id,
            'owner' => new UserResource($this->whenLoaded('user')),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Calculate a human-friendly age string.
     */
    private function calculateAge(): array
    {
        $now = now();
        $years = $now->diffInYears($this->birth_date);
        $months = $now->diffInMonths($this->birth_date) % 12;

        return [
            'years' => $years,
            'months' => $months,
            'label' => $years > 0
                ? "{$years} ano" . ($years > 1 ? 's' : '') . ($months > 0 ? " e {$months} mes" . ($months > 1 ? 'es' : '') : '')
                : "{$months} mes" . ($months > 1 ? 'es' : ''),
        ];
    }
}
