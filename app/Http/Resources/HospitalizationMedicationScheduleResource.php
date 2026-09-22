<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma linha de `GET hospitalizations/{id}/medication-schedule` — contrato
 * docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md §3. `next_due_at`/`is_late`
 * são sempre derivados (nunca gravados) — ver `HospitalizationMedicationScheduleService`.
 *
 * @mixin array{prescription_item: \App\Models\PrescriptionItem, last_given_at: ?\Illuminate\Support\Carbon, next_due_at: ?\Illuminate\Support\Carbon, is_late: bool}
 */
class HospitalizationMedicationScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'prescription_item' => new PrescriptionItemResource($this->resource['prescription_item']),
            'last_given_at' => $this->resource['last_given_at']?->toISOString(),
            'next_due_at' => $this->resource['next_due_at']?->toISOString(),
            'is_late' => $this->resource['is_late'],
        ];
    }
}
