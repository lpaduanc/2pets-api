<?php

namespace App\Http\Resources\Catalog;

use App\Models\AppointmentType;
use App\Models\Holiday;
use App\Models\HospitalizationBox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
class CatalogItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'active' => $this->active,
            'organization_id' => $this->organization_id,
            'professional_id' => $this->professional_id,
            ...$this->extraFields(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extraFields(): array
    {
        return match (true) {
            $this->resource instanceof Holiday => [
                'date' => $this->date?->toDateString(),
                'recurring_annually' => $this->recurring_annually,
                'scope' => $this->scope?->value,
                'scope_label' => $this->scope?->label(),
                'uf' => $this->uf,
                'city_ibge_code' => $this->city_ibge_code,
                // Feriado nacional (`HolidaySeeder`) não tem dono — o front usa esta flag para
                // desenhar como não editável/apagável (mesma regra que o backend já aplica).
                'is_national' => $this->organization_id === null && $this->professional_id === null,
            ],
            $this->resource instanceof HospitalizationBox => [
                'capacity' => $this->capacity,
                'notes' => $this->notes,
            ],
            $this->resource instanceof AppointmentType => [
                'category' => $this->category?->value,
                'default_duration_minutes' => $this->default_duration_minutes,
                'color' => $this->color,
            ],
            default => [],
        };
    }
}
