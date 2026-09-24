<?php

namespace App\Services\Location;

use App\Contracts\GeocodingProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Porta de entrada da geocodificação para o resto da aplicação: cache + provedor.
 *
 * O provedor concreto (Google hoje) vem do contrato `GeocodingProvider`, ligado em
 * `AppServiceProvider`. Aqui mora só a política de cache — 30 dias, o limite dos Termos do
 * Google Maps Platform para resultado de geocoding (ver `AddressLookupService`).
 *
 * `Cache::remember` não guarda `null`: falha nunca é cacheada, então um endereço que não
 * resolveu hoje é consultado de novo na próxima tentativa (é o que permite o reprocessamento
 * de `GeocodeUserAddress`).
 */
final class GeocodingService
{
    private const CACHE_TTL_DAYS = 30;

    public function __construct(private readonly GeocodingProvider $provider) {}

    /**
     * Se há provedor configurado para consultar.
     *
     * Público porque quem chama precisa distinguir "não achei o endereço" de "não consigo
     * consultar" — os dois viram `null` aqui, e o frontend trata cada um de um jeito (ver
     * `App\Enums\Location\AddressLookupStatus`).
     */
    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * Converte um endereço textual em coordenadas geográficas.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(string $address): ?array
    {
        if (trim($address) === '' || ! $this->isConfigured()) {
            return null;
        }

        return Cache::store(config('services.geocoding.cache_store'))->remember(
            $this->buildCacheKey('geocode', $address),
            now()->addDays(self::CACHE_TTL_DAYS),
            fn (): ?array => $this->provider->geocode($address)
        );
    }

    /**
     * Converte coordenadas geográficas em dados de endereço.
     *
     * @return array<string, string|null>|null formato em `GeocodingProvider::reverseGeocode()`
     */
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Cache::store(config('services.geocoding.cache_store'))->remember(
            $this->buildCacheKey('reverse', "{$lat},{$lng}"),
            now()->addDays(self::CACHE_TTL_DAYS),
            fn (): ?array => $this->provider->reverseGeocode($lat, $lng)
        );
    }

    /**
     * Gera chave de cache deterministica para o tipo e input fornecidos.
     */
    private function buildCacheKey(string $type, string $input): string
    {
        $hash = md5(mb_strtolower(trim($input)));

        return "geocoding:{$type}:{$hash}";
    }
}
