<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Surgery row for the professional's surgery list/detail.
 *
 * Expects `pet.user` eager-loaded (`Surgery::with(['pet.user', 'professional'])`) so
 * `pet.tutor_name` (flat, matches `SurgeriesPage.vue`) and `tutor` (full contact) can
 * be hoisted without an N+1 per row.
 */
class SurgeryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'professional_id' => $this->professional_id,
            'surgery_date' => $this->surgery_date?->format('Y-m-d'),
            'surgery_type' => $this->surgery_type,
            'pre_op_notes' => $this->pre_op_notes,
            'procedure_description' => $this->procedure_description,
            'post_op_notes' => $this->post_op_notes,
            'anesthesia_used' => $this->anesthesia_used,
            'complications' => $this->complications,
            'status' => $this->status,
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
            'tutor_name' => $this->tutorUser()?->name,
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
