<?php

namespace App\Http\Resources;

use App\Support\PetAgeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource used for the "vet's patient list" view.
 *
 * Wraps a PetVetAccess row but hoists key pet/tutor data to the top level so the
 * frontend can render cards without extra requests. Also exposes derived fields
 * (last visit, next pending event) that aren't persisted.
 *
 * Expects the following to be eager-loaded or pre-computed on the model:
 *   - relations: `pet.breedRelation`, `grantor`
 *   - attributes set via ::withDerivedFields():
 *       * `last_visit_at`  — latest completed appointment for (pet, vet) pair
 *       * `next_event`     — soonest upcoming vaccine/deworming
 */
class PetPatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\PetVetAccess $this */
        $pet = $this->pet;
        $grantor = $this->grantor;

        return [
            'access_id' => $this->id,
            'access_level' => $this->access_level,
            'status' => $this->status,
            'granted_at' => $this->granted_at?->toISOString(),
            'is_active' => $this->is_active,

            'pet' => $pet ? [
                'id' => $pet->id,
                'public_id' => $pet->public_id,
                'name' => $pet->name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'breed_name' => $pet->breedRelation?->name ?? $pet->breed,
                'gender' => $pet->gender,
                'birth_date' => $pet->birth_date?->format('Y-m-d'),
                'age' => $this->calculateAge($pet->birth_date),
                'weight' => $pet->weight ? (float) $pet->weight : null,
                'image' => $pet->image_url,
                'neutered' => $pet->neutered,
                'is_lost' => (bool) ($pet->is_lost ?? false),
                // Global ao pet (algum medical_record finalizado, de QUALQUER profissional) —
                // não confundir com `last_visit_at` abaixo, que é por vínculo com ESTE vet.
                // Ver docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md §2.
                'has_finalized_record' => (bool) ($this->resource->has_finalized_record ?? false),
            ] : null,

            'tutor' => $grantor ? [
                'id' => $grantor->id,
                'name' => $grantor->name,
                'phone' => $grantor->phone,
                'email' => $grantor->email,
                'has_avatar' => $grantor->relationLoaded('media')
                    ? $grantor->getFirstMediaUrl('avatar') !== ''
                    : null,
            ] : null,

            // Derived, populated by the controller (nullable when unknown).
            'last_visit_at' => optional($this->resource->last_visit_at ?? null)?->toISOString(),
            'next_event' => $this->resource->next_event ?? null,
        ];
    }

    /**
     * Human-friendly age. Returns null when no birth_date.
     */
    private function calculateAge(?\Carbon\Carbon $birth): ?array
    {
        return $birth ? PetAgeCalculator::calculate($birth) : null;
    }
}
