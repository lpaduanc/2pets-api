<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1.
 *
 * `author` reaproveita `VetContactResource` (nome + CRMV + UF, já minimizado por LGPD) em
 * vez de montar o mesmo formato de novo — exige `author.professional` eager-loaded.
 */
class HospitalizationProgressNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hospitalization_id' => $this->hospitalization_id,
            'author' => new VetContactResource($this->whenLoaded('author')),
            'recorded_at' => $this->recorded_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'body' => $this->body,
            'temperature' => $this->temperature,
            'heart_rate' => $this->heart_rate,
            'respiratory_rate' => $this->respiratory_rate,
            'weight' => $this->weight,
            'corrects_id' => $this->corrects_id,
        ];
    }
}
