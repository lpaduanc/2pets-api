<?php

namespace App\Contracts;

use App\DataTransferObjects\Location\AddressSearchQuery;
use App\DataTransferObjects\Location\AddressSuggestion;

/**
 * Fronteira com o provedor de autocomplete de endereço (Google Places e Photon hoje).
 *
 * Separado de `GeocodingProvider` (ISP): nem todo serviço de mapas serve para os dois — o
 * Nominatim público PROÍBE uso como autocomplete, e o Photon existe justamente para isso.
 *
 * Mesma regra de falha do geocoding: nunca exceção. `null` = não consegui consultar (rede,
 * cota, chave recusada); `[]` = consultei e não há endereço para o texto. O cache mora em
 * `App\Services\Location\AddressSearchService`, não aqui.
 */
interface AddressSuggestionProvider
{
    /** Se o provedor tem o que precisa (chave, URL) para responder. */
    public function isConfigured(): bool;

    /**
     * Texto → endereços no Brasil, todos com coordenada. Pede `providerLimit()` itens e NÃO
     * corta em `limit`: quem deduplica e corta é `AddressSearchService`.
     *
     * @return list<AddressSuggestion>|null
     */
    public function suggest(AddressSearchQuery $query): ?array;
}
