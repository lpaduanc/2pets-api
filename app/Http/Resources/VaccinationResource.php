<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vaccination row for the professional's vaccination list/detail.
 *
 * Expects `pet.user` eager-loaded (`Vaccination::with(['pet.user', 'professional'])`)
 * so `tutor` can be hoisted to the top level without an N+1 per row.
 */
class VaccinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'professional_id' => $this->professional_id,
            'appointment_id' => $this->appointment_id,
            'vaccine_name' => $this->vaccine_name,
            'manufacturer' => $this->manufacturer,
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'inventory_id' => $this->inventory_id,
            'application_date' => $this->application_date?->format('Y-m-d'),
            'next_dose_date' => $this->next_dose_date?->format('Y-m-d'),
            'dose_number' => $this->dose_number,
            'notes' => $this->notes,
            'adverse_reactions' => $this->adverse_reactions,
            'pet' => $this->petSummary(),
            'tutor' => $this->tutorContact(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function petSummary(): ?array
    {
        if (! $this->relationLoaded('pet') || ! $this->pet) {
            return null;
        }

        return [
            'id' => $this->pet->id,
            'name' => $this->pet->name,
            'species' => $this->pet->species,
        ];
    }

    private function tutorContact(): ?TutorContactResource
    {
        $tutor = $this->tutorUser();

        return $tutor ? new TutorContactResource($tutor) : null;
    }

    private function tutorUser(): ?User
    {
        if (! $this->relationLoaded('pet') || ! $this->pet) {
            return null;
        }

        return $this->pet->relationLoaded('user') ? $this->pet->user : null;
    }
}
