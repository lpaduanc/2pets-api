<?php

namespace App\Services\Location\Providers;

use App\Contracts\AddressSuggestionProvider;
use App\DataTransferObjects\Location\AddressSearchQuery;
use App\DataTransferObjects\Location\AddressSuggestion;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Places API (New) — Text Search. Mesma `GOOGLE_MAPS_API_KEY` e mesmo liga/desliga
 * (`google`) do geocoding. Sem chave, não chama o Google (ver `GoogleGeocodingProvider`).
 *
 * O FieldMask pede só id, endereço, coordenada e componentes: o Google cobra pelo SKU do
 * campo mais caro pedido, e nome/telefone/avaliação subiriam a categoria sem uso nenhum aqui.
 *
 * `regionCode` só enviesa; a restrição ao Brasil é o filtro por `country` nos componentes.
 */
final class GooglePlacesSuggestionProvider implements AddressSuggestionProvider
{
    private const API_URL = 'https://places.googleapis.com/v1/places:searchText';

    private const FIELD_MASK = 'places.id,places.formattedAddress,places.location,places.addressComponents';

    private const TIMEOUT_SECONDS = 10;

    private const COUNTRY_CODE = 'BR';

    /** Prefixo do `id` da sugestão: o place id do Google não colide com o id OSM do Photon. */
    private const ID_PREFIX = 'google:';

    /** Raio do viés de proximidade — 50 km é o máximo que a Text Search aceita. */
    private const BIAS_RADIUS_METERS = 50000.0;

    public function isConfigured(): bool
    {
        return trim($this->apiKey()) !== '';
    }

    public function suggest(AddressSearchQuery $query): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $places = $this->searchText($query);

        if ($places === null) {
            return null;
        }

        return collect($places)
            ->filter(fn (array $place): bool => $this->component(collect($place['addressComponents'] ?? []), 'country', 'shortText') === self::COUNTRY_CODE)
            ->map(fn (array $place): ?AddressSuggestion => $this->toSuggestion($place))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function searchText(AddressSearchQuery $query): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['X-Goog-Api-Key' => $this->apiKey(), 'X-Goog-FieldMask' => self::FIELD_MASK])
                ->post(self::API_URL, $this->body($query));
        } catch (Throwable $exception) {
            Log::error('GooglePlacesSuggestionProvider: exception during request', ['query' => $query->text, 'error' => $exception->getMessage()]);

            return null;
        }

        return $this->places($response, $query);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function places(Response $response, AddressSearchQuery $query): ?array
    {
        if ($response->failed()) {
            Log::info('GooglePlacesSuggestionProvider: request failed', ['query' => $query->text, 'http_status' => $response->status()]);

            return null;
        }

        $places = $response->json('places', []);

        return is_array($places) ? $places : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AddressSearchQuery $query): array
    {
        $body = ['textQuery' => $query->text, 'regionCode' => self::COUNTRY_CODE, 'languageCode' => 'pt-BR', 'pageSize' => $query->providerLimit()];

        if (! $query->hasBias()) {
            return $body;
        }

        return [...$body, 'locationBias' => ['circle' => [
            'center' => ['latitude' => $query->latitude, 'longitude' => $query->longitude],
            'radius' => self::BIAS_RADIUS_METERS,
        ]]];
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function toSuggestion(array $place): ?AddressSuggestion
    {
        $components = collect($place['addressComponents'] ?? []);

        return AddressSuggestion::fromComponents(self::ID_PREFIX.($place['id'] ?? ''), [
            'formatted' => $place['formattedAddress'] ?? null,
            'street' => $this->component($components, 'route'),
            'number' => $this->component($components, 'street_number'),
            'neighborhood' => $this->component($components, 'sublocality_level_1') ?? $this->component($components, 'sublocality'),
            'city' => $this->component($components, 'administrative_area_level_2'),
            'state' => $this->component($components, 'administrative_area_level_1'),
            'state_code' => $this->component($components, 'administrative_area_level_1', 'shortText'),
            'zip_code' => $this->component($components, 'postal_code'),
            'name' => $place['formattedAddress'] ?? null,
        ], $this->coordinate($place, 'latitude'), $this->coordinate($place, 'longitude'));
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function coordinate(array $place, string $axis): ?float
    {
        $value = $place['location'][$axis] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $components
     */
    private function component(Collection $components, string $type, string $nameField = 'longText'): ?string
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
