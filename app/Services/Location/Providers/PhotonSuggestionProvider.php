<?php

namespace App\Services\Location\Providers;

use App\Contracts\AddressSuggestionProvider;
use App\DataTransferObjects\Location\AddressSearchQuery;
use App\DataTransferObjects\Location\AddressSuggestion;
use App\Enums\Location\BrazilianState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Photon (komoot) — busca sobre o OpenStreetMap feita para autocomplete, gratuita e sem chave.
 * É o autocomplete do OSM porque o Nominatim público PROÍBE esse uso. Liga/desliga em
 * `GEOCODING_PHOTON_ENABLED`; instância própria em `PHOTON_BASE_URL`.
 *
 * Restrição ao Brasil em duas camadas: `bbox` (o retângulo do país, que ainda pega borda de
 * vizinhos) e o filtro por `countrycode` na resposta. Por isso pede alguns itens a mais.
 */
final class PhotonSuggestionProvider implements AddressSuggestionProvider
{
    private const TIMEOUT_SECONDS = 10;

    private const COUNTRY_CODE = 'BR';

    /** min. longitude, min. latitude, máx. longitude, máx. latitude do Brasil. */
    private const BRAZIL_BBOX = '-74.0,-33.8,-34.7,5.3';

    /** Itens extras pedidos para compensar os de país vizinho que o `bbox` deixa passar. */
    private const OVERFETCH = 3;

    /** Tipos do Photon em que o `name` É a cidade (o item não traz `city`). */
    private const CITY_TYPES = ['city', 'town', 'village'];

    private const STREET_OSM_KEY = 'highway';

    public function isConfigured(): bool
    {
        return trim($this->baseUrl()) !== '' && trim($this->userAgent()) !== '';
    }

    public function suggest(AddressSearchQuery $query): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $features = $this->features($query);

        if ($features === null) {
            return null;
        }

        return collect($features)
            ->filter(fn (array $feature): bool => ($feature['properties']['countrycode'] ?? null) === self::COUNTRY_CODE)
            ->map(fn (array $feature): ?AddressSuggestion => $this->toSuggestion($feature))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function features(AddressSearchQuery $query): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent($this->userAgent())
                ->get(rtrim($this->baseUrl(), '/').'/api', $this->parameters($query));
        } catch (Throwable $exception) {
            Log::error('PhotonSuggestionProvider: exception during request', ['query' => $query->text, 'error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::info('PhotonSuggestionProvider: request failed', ['query' => $query->text, 'http_status' => $response->status()]);

            return null;
        }

        $features = $response->json('features', []);

        return is_array($features) ? $features : [];
    }

    /**
     * @return array<string, string|int|float>
     */
    private function parameters(AddressSearchQuery $query): array
    {
        $parameters = ['q' => $query->text, 'limit' => $query->providerLimit() + self::OVERFETCH, 'bbox' => self::BRAZIL_BBOX];

        if (! $query->hasBias()) {
            return $parameters;
        }

        return [...$parameters, 'lat' => $query->latitude, 'lon' => $query->longitude];
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    private function toSuggestion(array $feature): ?AddressSuggestion
    {
        $properties = $feature['properties'] ?? [];
        $coordinates = $feature['geometry']['coordinates'] ?? [];
        $isStreet = ($properties['osm_key'] ?? null) === self::STREET_OSM_KEY;
        $isCity = in_array($properties['type'] ?? null, self::CITY_TYPES, true);

        return AddressSuggestion::fromComponents($this->id($properties), [
            'name' => $properties['name'] ?? null,
            'street' => $properties['street'] ?? ($isStreet ? ($properties['name'] ?? null) : null),
            'number' => $properties['housenumber'] ?? null,
            'neighborhood' => $properties['district'] ?? $properties['locality'] ?? null,
            'city' => $properties['city'] ?? ($isCity ? ($properties['name'] ?? null) : null),
            'state' => $properties['state'] ?? null,
            'state_code' => BrazilianState::fromName($properties['state'] ?? null)?->value,
            'zip_code' => $properties['postcode'] ?? null,
            'formatted' => $this->formatted($properties),
        ], $this->coordinate($coordinates, 1), $this->coordinate($coordinates, 0));
    }

    /**
     * "osm:W:832562652" — estável entre buscas: é o objeto do OpenStreetMap.
     *
     * @param  array<string, mixed>  $properties
     */
    private function id(array $properties): string
    {
        return 'osm:'.($properties['osm_type'] ?? '').':'.($properties['osm_id'] ?? '');
    }

    /**
     * O Photon não devolve endereço formatado; monta do específico ao geral, como o Google.
     *
     * @param  array<string, mixed>  $properties
     */
    private function formatted(array $properties): string
    {
        $keys = ['name', 'street', 'housenumber', 'district', 'city', 'state', 'postcode'];
        $parts = array_map(fn (string $key): ?string => $properties[$key] ?? null, $keys);

        return implode(', ', array_unique(array_filter($parts)));
    }

    /**
     * GeoJSON: `coordinates` é [longitude, latitude] — longitude PRIMEIRO.
     *
     * @param  array<int, mixed>  $coordinates
     */
    private function coordinate(array $coordinates, int $index): ?float
    {
        $value = $coordinates[$index] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function baseUrl(): string
    {
        return (string) config('services.photon.base_url', '');
    }

    private function userAgent(): string
    {
        return (string) config('services.photon.user_agent', '');
    }
}
