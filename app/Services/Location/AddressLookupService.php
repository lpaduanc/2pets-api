<?php

namespace App\Services\Location;

use App\DataTransferObjects\Location\AddressLookup;

/**
 * Traduz o `?array` do `GeocodingService` num resultado que diz POR QUE não resolveu.
 *
 * Existe para que o controller não precise perguntar duas coisas ao provedor ("está
 * configurado?" e "achou?") nem decidir o que cada combinação significa — isso é regra de
 * negócio, não HTTP. E para que a decisão fique num lugar só quando houver um segundo
 * provedor: trocar Google por outro é implementar o contrato, não mexer no controller.
 *
 * ── Dívida registrada, deliberadamente não resolvida agora ────────────────────────────
 * Os Termos do Google Maps Platform limitam o cache de resultado de geocoding a 30 dias, e é
 * por isso que `GeocodingService::CACHE_TTL_DAYS` vale 30. O dono do produto decidiu manter o
 * Google ciente disso. Consequência prática: não dá para construir uma base própria de
 * coordenada→endereço em cima deste cache. Se isso virar requisito, o caminho é outro
 * provedor (Nominatim/ViaCEP) ou um contrato que permita armazenamento — não esticar o TTL.
 */
final class AddressLookupService
{
    public function __construct(private readonly GeocodingService $geocodingService) {}

    public function forCoordinates(float $latitude, float $longitude): AddressLookup
    {
        if (! $this->geocodingService->isConfigured()) {
            return AddressLookup::unavailable($latitude, $longitude);
        }

        $address = $this->geocodingService->reverseGeocode($latitude, $longitude);

        return $address === null
            ? AddressLookup::notFound($latitude, $longitude)
            : AddressLookup::resolved($latitude, $longitude, $address);
    }
}
