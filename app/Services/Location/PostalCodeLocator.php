<?php

namespace App\Services\Location;

use App\DataTransferObjects\Location\PostalAddress;
use App\DataTransferObjects\Location\ResolvedPlace;
use App\Exceptions\Location\PostalCodeLookupUnavailableException;
use App\Exceptions\Location\PostalCodeNotFoundException;

/**
 * CEP → coordenada: ViaCEP preenche o endereço, o geocoding situa no mapa.
 *
 * É a origem da busca quando o tutor não tem (ou não quer dar) a localização do aparelho —
 * nunca é usado para situar PROFISSIONAL, cujo ponto é geocodificado uma vez no cadastro.
 * As duas etapas têm cache próprio (`ViaCepClient` e `GeocodingService`), então o mesmo CEP
 * buscado de novo não sai para a rede.
 */
final class PostalCodeLocator
{
    public function __construct(
        private readonly ViaCepClient $viaCepClient,
        private readonly GeocodingService $geocodingService,
    ) {}

    /**
     * @throws PostalCodeNotFoundException CEP inexistente, ou nem a cidade dele geocodifica
     * @throws PostalCodeLookupUnavailableException ViaCEP fora do ar ou geocoding sem provedor
     */
    public function locate(string $zipCode): ResolvedPlace
    {
        $address = $this->viaCepClient->lookup($zipCode) ?? throw new PostalCodeNotFoundException($zipCode);

        if (! $this->geocodingService->isConfigured()) {
            throw PostalCodeLookupUnavailableException::geocoding();
        }

        $coordinates = $this->coordinatesFor($address) ?? throw new PostalCodeNotFoundException($zipCode);

        return new ResolvedPlace($address, $coordinates['latitude'], $coordinates['longitude']);
    }

    /**
     * Recua para o centro da cidade quando o endereço do CEP não geocodifica — "perto de
     * Poços de Caldas" é uma origem útil; nenhuma origem trava o tutor.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function coordinatesFor(PostalAddress $address): ?array
    {
        return $this->geocodingService->geocode($address->geocodingQuery())
            ?? $this->geocodingService->geocode($address->cityGeocodingQuery());
    }
}
