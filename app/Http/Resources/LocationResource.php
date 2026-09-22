<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET professional/locations` — seletor de unidade para o filtro `location_id` de
 * `professional/agenda/day` (achado do frontend, item 21). Só os campos que um seletor
 * precisa: nome e endereço curto para desambiguar duas unidades com nome parecido.
 *
 * @mixin \App\Models\Location
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'state' => $this->state,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
        ];
    }
}
