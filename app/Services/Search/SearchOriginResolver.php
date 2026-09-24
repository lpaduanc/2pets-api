<?php

namespace App\Services\Search;

use App\DataTransferObjects\Location\ResolvedPlace;
use App\Services\Location\PostalCodeLocator;

/**
 * Origem da busca por proximidade: coordenada explícita (GPS do aparelho) ou CEP.
 *
 * Coordenada vence o CEP quando os dois chegam — é a mais precisa, e o app manda as duas
 * quando o tutor confirmou a posição mas o CEP ficou salvo de antes. Sem coordenada, o CEP
 * é resolvido no backend (`PostalCodeLocator`: ViaCEP + geocoding, os dois com cache) e a
 * busca segue EXATAMENTE o mesmo caminho da busca por lat/lng.
 */
final class SearchOriginResolver
{
    public function __construct(private readonly PostalCodeLocator $postalCodeLocator) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function placeFor(array $validated): ?ResolvedPlace
    {
        if (isset($validated['latitude'], $validated['longitude']) || blank($validated['zip_code'] ?? null)) {
            return null;
        }

        return $this->postalCodeLocator->locate((string) $validated['zip_code']);
    }

    /**
     * Os filtros validados com a coordenada do CEP no lugar da coordenada ausente.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function withOrigin(array $validated, ?ResolvedPlace $place): array
    {
        if ($place === null) {
            return $validated;
        }

        return [...$validated, 'latitude' => $place->latitude, 'longitude' => $place->longitude];
    }
}
