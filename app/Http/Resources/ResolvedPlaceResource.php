<?php

namespace App\Http\Resources;

use App\DataTransferObjects\Location\ResolvedPlace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um CEP situado no mapa — a origem da busca do TUTOR. A coordenada aqui é do centro do
 * CEP (ou da cidade), nunca de um profissional, então não há o que arredondar.
 *
 * @property ResolvedPlace $resource
 */
class ResolvedPlaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
