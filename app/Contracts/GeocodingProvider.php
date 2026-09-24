<?php

namespace App\Contracts;

/**
 * Fronteira com o provedor de geocodificação (Google Geocoding API hoje; Nominatim ou outro
 * amanhã). Trocar de provedor é implementar este contrato e mudar o bind em
 * `AppServiceProvider::register()` — nenhum consumidor conhece o provedor concreto.
 *
 * Implementações NÃO cacheiam: o cache mora em `App\Services\Location\GeocodingService`, que
 * envolve o provedor, para que a política (TTL de 30 dias dos termos do Google) fique num
 * lugar só independentemente de quem responde.
 *
 * Toda falha (rede, cota, chave recusada, endereço inexistente) vira `null` — nunca exceção.
 * Quem precisa distinguir "não achei" de "não consigo consultar" pergunta `isConfigured()`.
 */
interface GeocodingProvider
{
    /** Se o provedor tem o que precisa (chave, URL) para responder. */
    public function isConfigured(): bool;

    /**
     * Endereço textual → coordenada.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(string $address): ?array;

    /**
     * Coordenada → endereço estruturado.
     *
     * @return array{
     *     formatted_address: string|null,
     *     street: string|null,
     *     number: string|null,
     *     neighborhood: string|null,
     *     city: string|null,
     *     state: string|null,
     *     state_short: string|null,
     *     country: string|null,
     *     zip_code: string|null,
     * }|null
     */
    public function reverseGeocode(float $latitude, float $longitude): ?array;
}
