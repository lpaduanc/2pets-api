<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Full resource for single pet view (show endpoint).
 * Includes all relations and extended profile details.
 */
class PetDetailResource extends PetResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            // Effective access level for the authenticated requester.
            // Populated by PetController::show via setAttribute(); falls back to 'read'
            // to keep the frontend's read-only default safe when missing.
            'access_level' => $this->access_level_for_requester ?? 'read',

            // Feeding
            'food_types' => $this->food_types,
            'food_brand' => $this->food_brand,
            'dietary_restrictions' => $this->dietary_restrictions,
            'food_allergies' => $this->food_allergies,
            'food_allergies_other' => $this->food_allergies_other,

            // Health details
            'blood_type' => $this->blood_type,
            'allergies' => $this->allergies,
            'has_allergies' => ! empty($this->allergies),
            'chronic_diseases' => $this->chronic_diseases,
            'chronic_conditions' => $this->chronic_conditions,
            'surgeries' => $this->surgeries,
            'previous_hospitalizations' => $this->previous_hospitalizations,
            'current_medications' => $this->current_medications,

            // Behavior
            'temperament' => $this->temperament,
            'behavior_notes' => $this->behavior_notes,
            'social_with' => $this->social_with,

            // Exercise
            'does_exercise' => $this->does_exercise,
            'exercise_types' => $this->exercise_types,
            'exercise_frequency' => $this->exercise_frequency,
            'daily_walk' => $this->daily_walk,
            'docile_with_strangers' => $this->docile_with_strangers,
            'docile_with_animals' => $this->docile_with_animals,

            // Lost pet details
            'lost_alert_message' => $this->when($this->is_lost, $this->lost_alert_message),

            // Notes
            'notes' => $this->notes,

            // Vaccination status
            'vaccines_up_to_date' => $this->whenLoaded('vaccinations', function () {
                return $this->vaccinations
                    ->filter(fn ($v) => $v->next_dose_date && $v->next_dose_date < now())
                    ->isEmpty();
            }, true),

            // Related data -- only included when eager-loaded
            'vaccinations' => $this->whenLoaded('vaccinations'),
            'dewormings' => $this->whenLoaded('dewormings'),
            'medications' => $this->whenLoaded('medications'),
            'weight_history' => $this->whenLoaded('weightHistory'),
            'vet_accesses' => $this->whenLoaded('vetAccesses'),

            // Owner
            'owner' => new UserResource($this->whenLoaded('user')),
        ]);
    }
}
