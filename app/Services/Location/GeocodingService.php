<?php

namespace App\Services\Location;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GeocodingService
{
    private const CACHE_TTL_DAYS = 30;
    private const API_BASE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.google.maps_api_key', '');
    }

    /**
     * Converte um endereço textual em coordenadas geográficas.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(string $address): ?array
    {
        if (empty(trim($address))) {
            return null;
        }

        $cacheKey = $this->buildCacheKey('geocode', $address);

        return Cache::store('redis')->remember(
            $cacheKey,
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->performGeocode($address)
        );
    }

    /**
     * Converte coordenadas geográficas em dados de endereço.
     *
     * @return array{
     *     formatted_address: string,
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
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        $cacheKey = $this->buildCacheKey('reverse', "{$lat},{$lng}");

        return Cache::store('redis')->remember(
            $cacheKey,
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->performReverseGeocode($lat, $lng)
        );
    }

    /**
     * Executa a chamada de geocodificação na API do Google Maps.
     */
    private function performGeocode(string $address): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL, [
                'address' => $address,
                'key' => $this->apiKey,
                'region' => 'BR',
                'language' => 'pt-BR',
            ]);

            if ($response->failed()) {
                Log::warning('GeocodingService: HTTP request failed', [
                    'address' => $address,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
                Log::info('GeocodingService: No results for address', [
                    'address' => $address,
                    'status' => $data['status'] ?? 'UNKNOWN',
                ]);
                return null;
            }

            $location = $data['results'][0]['geometry']['location'];

            return [
                'latitude' => (float) $location['lat'],
                'longitude' => (float) $location['lng'],
            ];
        } catch (\Throwable $e) {
            Log::error('GeocodingService: Exception during geocode', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Executa a chamada de geocodificacao reversa na API do Google Maps.
     */
    private function performReverseGeocode(float $lat, float $lng): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL, [
                'latlng' => "{$lat},{$lng}",
                'key' => $this->apiKey,
                'region' => 'BR',
                'language' => 'pt-BR',
            ]);

            if ($response->failed()) {
                Log::warning('GeocodingService: Reverse geocode HTTP failed', [
                    'lat' => $lat,
                    'lng' => $lng,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
                Log::info('GeocodingService: No reverse geocode results', [
                    'lat' => $lat,
                    'lng' => $lng,
                    'status' => $data['status'] ?? 'UNKNOWN',
                ]);
                return null;
            }

            $result = $data['results'][0];

            return $this->parseAddressComponents($result);
        } catch (\Throwable $e) {
            Log::error('GeocodingService: Exception during reverse geocode', [
                'lat' => $lat,
                'lng' => $lng,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrai componentes do endereço da resposta da API do Google.
     */
    private function parseAddressComponents(array $result): array
    {
        $components = collect($result['address_components'] ?? []);

        $getComponent = function (string $type) use ($components): ?string {
            $component = $components->first(
                fn (array $c) => in_array($type, $c['types'] ?? [])
            );
            return $component['long_name'] ?? null;
        };

        $getComponentShort = function (string $type) use ($components): ?string {
            $component = $components->first(
                fn (array $c) => in_array($type, $c['types'] ?? [])
            );
            return $component['short_name'] ?? null;
        };

        return [
            'formatted_address' => $result['formatted_address'] ?? null,
            'street' => $getComponent('route'),
            'number' => $getComponent('street_number'),
            'neighborhood' => $getComponent('sublocality_level_1')
                ?? $getComponent('sublocality'),
            'city' => $getComponent('administrative_area_level_2'),
            'state' => $getComponent('administrative_area_level_1'),
            'state_short' => $getComponentShort('administrative_area_level_1'),
            'country' => $getComponent('country'),
            'zip_code' => $getComponent('postal_code'),
        ];
    }

    /**
     * Gera chave de cache deterministica para o tipo e input fornecidos.
     */
    private function buildCacheKey(string $type, string $input): string
    {
        $normalized = mb_strtolower(trim($input));
        $hash = md5($normalized);

        return "geocoding:{$type}:{$hash}";
    }
}
