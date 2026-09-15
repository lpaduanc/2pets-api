<?php

namespace App\Http\Resources;

use App\DataTransferObjects\Location\AddressLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato de `GET /api/public/reverse-geocode`.
 *
 * Forma ESTÁVEL: as chaves de topo existem sempre, resolvido ou não. O frontend lê `resolved`
 * para decidir o caminho e `status` para saber o motivo, sem precisar inspecionar código HTTP
 * nem procurar `message`.
 *
 * ```json
 * {
 *   "resolved": true,
 *   "status": "ok",
 *   "latitude": -23.5505,
 *   "longitude": -46.6333,
 *   "address": {
 *     "formatted": "Av. Paulista, 1578 - Bela Vista, São Paulo - SP, 01310-200",
 *     "street": "Avenida Paulista", "number": "1578", "neighborhood": "Bela Vista",
 *     "city": "São Paulo", "state": "São Paulo", "state_code": "SP",
 *     "zip_code": "01310-200", "country": "Brasil"
 *   }
 * }
 * ```
 *
 * Não resolvido — `address` é `null` e as coordenadas voltam para o fallback:
 *
 * ```json
 * { "resolved": false, "status": "unavailable", "latitude": -23.5505, "longitude": -46.6333, "address": null }
 * ```
 *
 * `$wrap = null` de propósito: é um recurso único, não uma coleção, e um envelope `data`
 * obrigaria o frontend a desembrulhar sem ganho nenhum — mesma decisão já tomada em
 * `GET /api/profile` (ver `agent-memory/.../contratos-api.md`).
 *
 * @property AddressLookup $resource
 */
class AddressLookupResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'resolved' => $this->resource->status->isResolved(),
            'status' => $this->resource->status->value,
            'latitude' => $this->resource->latitude,
            'longitude' => $this->resource->longitude,
            'address' => $this->address(),
        ];
    }

    /**
     * Chaves fixas mesmo quando o Google não devolve o componente — um endereço com `number`
     * ausente e um endereço com `number: null` são a mesma coisa para o frontend, e chave que
     * às vezes existe obriga o cliente a checar antes de ler.
     *
     * @return array<string, string|null>|null
     */
    private function address(): ?array
    {
        $address = $this->resource->address;

        if ($address === null) {
            return null;
        }

        return [
            'formatted' => $address['formatted_address'] ?? null,
            'street' => $address['street'] ?? null,
            'number' => $address['number'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'state_code' => $address['state_short'] ?? null,
            'zip_code' => $address['zip_code'] ?? null,
            'country' => $address['country'] ?? null,
        ];
    }
}
