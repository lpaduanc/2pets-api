<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4.
 */
class HospitalizationCareLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hospitalization_id' => $this->hospitalization_id,
            'author' => new VetContactResource($this->whenLoaded('author')),
            'care_type' => $this->care_type->value,
            'status' => $this->status->value,
            'performed_at' => $this->performed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'notes' => $this->notes,
            'prescription_item_id' => $this->prescription_item_id,
        ];
    }
}
