<?php

namespace App\Services\Location\Providers;

use App\Contracts\GeocodingProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Geocoding API. Chave em `GOOGLE_MAPS_API_KEY` (`config('services.google.maps_api_key')`).
 *
 * Sem chave, não chama o Google: a requisição voltaria `REQUEST_DENIED` e, como o cache de
 * `GeocodingService` não guarda `null`, TODA chamada iria para a rede sem chance de sucesso.
 */
final class GoogleGeocodingProvider implements GeocodingProvider
{
    private const API_BASE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const TIMEOUT_SECONDS = 10;

    private const STATUS_OK = 'OK';

    public function isConfigured(): bool
    {
        return trim($this->apiKey()) !== '';
    }

    public function geocode(string $address): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // `components=country:BR` impede que "Centro, Santos" vire um Santos fora do Brasil.
        $result = $this->firstResult(['address' => $address, 'components' => 'country:BR'], ['address' => $address]);

        if ($result === null) {
            return null;
        }

        return [
            'latitude' => (float) $result['geometry']['location']['lat'],
            'longitude' => (float) $result['geometry']['location']['lng'],
        ];
    }

    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $result = $this->firstResult(
            ['latlng' => "{$latitude},{$longitude}"],
            ['latitude' => $latitude, 'longitude' => $longitude]
        );

        return $result === null ? null : $this->parseAddressComponents($result);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $logContext
     * @return array<string, mixed>|null
     */
    private function firstResult(array $query, array $logContext): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get(self::API_BASE_URL, [
                ...$query,
                'key' => $this->apiKey(),
                'region' => 'BR',
                'language' => 'pt-BR',
            ]);
        } catch (Throwable $exception) {
            Log::error('GoogleGeocodingProvider: exception during request', [...$logContext, 'error' => $exception->getMessage()]);

            return null;
        }

        $status = $response->json('status', 'UNKNOWN');
        $results = $response->json('results', []);

        if ($response->failed() || $status !== self::STATUS_OK || $results === []) {
            Log::info('GoogleGeocodingProvider: no result', [...$logContext, 'http_status' => $response->status(), 'status' => $status]);

            return null;
        }

        return $results[0];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string|null>
     */
    private function parseAddressComponents(array $result): array
    {
        $components = collect($result['address_components'] ?? []);

        return [
            'formatted_address' => $result['formatted_address'] ?? null,
            'street' => $this->component($components, 'route'),
            'number' => $this->component($components, 'street_number'),
            'neighborhood' => $this->component($components, 'sublocality_level_1')
                ?? $this->component($components, 'sublocality'),
            'city' => $this->component($components, 'administrative_area_level_2'),
            'state' => $this->component($components, 'administrative_area_level_1'),
            'state_short' => $this->component($components, 'administrative_area_level_1', 'short_name'),
            'country' => $this->component($components, 'country'),
            'zip_code' => $this->component($components, 'postal_code'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $components
     */
    private function component(Collection $components, string $type, string $nameField = 'long_name'): ?string
    {
        $component = $components->first(
            fn (array $candidate): bool => in_array($type, $candidate['types'] ?? [], true)
        );

        return $component[$nameField] ?? null;
    }

    private function apiKey(): string
    {
        return (string) config('services.google.maps_api_key', '');
    }
}
