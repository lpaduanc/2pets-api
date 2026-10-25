<?php

namespace App\Http\Resources;

use App\DataTransferObjects\Location\AddressSearchResult;
use App\DataTransferObjects\Location\AddressSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato de `GET /api/public/address-search`.
 *
 * Forma ESTÁVEL: `status` e `data` existem sempre; `data` é `[]` quando o status não é `ok`.
 *
 * ```json
 * { "status": "ok", "data": [ { "id": "osm:W:832562652", "title": "Rua Assis Figueiredo",
 *   "subtitle": "Centro, Poços de Caldas - MG", "formatted": "...", "street": "Rua Assis Figueiredo",
 *   "number": null, "neighborhood": "Centro", "city": "Poços de Caldas", "state": "Minas Gerais",
 *   "state_code": "MG", "zip_code": "37701-013", "latitude": -21.78, "longitude": -46.56 } ] }
 * ```
 *
 * `$wrap = null` porque o envelope já é montado aqui, com `status` ao lado de `data`.
 *
 * @property AddressSearchResult $resource
 */
class AddressSearchResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{status: string, data: list<array<string, string|float|null>>}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource->status->value,
            'data' => array_map(
                fn (AddressSuggestion $suggestion): array => $suggestion->toArray(),
                $this->resource->suggestions
            ),
        ];
    }
}
