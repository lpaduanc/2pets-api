<?php

namespace App\Services\Location\Providers;

use App\Contracts\GeocodingProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nominatim (OpenStreetMap). Gratuito e sem chave — o fallback do Google quando não há
 * `GOOGLE_MAPS_API_KEY` ou ele não acha (ligado por `GEOCODING_NOMINATIM_ENABLED`, ver
 * `ChainGeocodingProvider`).
 *
 * A instância pública tem política de uso que não é opcional
 * (https://operations.osmfoundation.org/policies/nominatim/):
 *  - User-Agent que identifique a aplicação, com contato (`services.nominatim.user_agent`);
 *  - no máximo 1 requisição por segundo — garantido aqui por um lock que expira sozinho;
 *  - resultados cacheados — já feito por `GeocodingService` (30 dias).
 * Volume alto (ex.: `geocoding:retry-failed` sobre a base inteira) pede instância própria:
 * basta apontar `NOMINATIM_BASE_URL` para ela.
 */
final class NominatimGeocodingProvider implements GeocodingProvider
{
    private const TIMEOUT_SECONDS = 10;

    private const RATE_LIMIT_LOCK = 'geocoding:nominatim:rate-limit';

    /** Quanto uma requisição espera pela vez antes de desistir (e virar `null`). */
    private const RATE_LIMIT_WAIT_SECONDS = 5;

    /** "BR-SP" → "SP": é o único lugar onde o Nominatim devolve a sigla do estado. */
    private const STATE_CODE_PREFIX = 'BR-';

    /**
     * Componente → chaves do Nominatim em ordem de preferência. O OSM não tem um campo único
     * para "bairro" nem para "cidade": varia com o tamanho do lugar e com quem mapeou.
     */
    private const COMPONENT_KEYS = [
        'street' => ['road', 'pedestrian', 'footway'],
        'number' => ['house_number'],
        'neighborhood' => ['suburb', 'neighbourhood', 'quarter', 'city_district'],
        'city' => ['city', 'town', 'village', 'municipality'],
        'state' => ['state'],
        'country' => ['country'],
        'zip_code' => ['postcode'],
    ];

    public function isConfigured(): bool
    {
        return trim($this->baseUrl()) !== '' && trim($this->userAgent()) !== '';
    }

    public function geocode(string $address): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // `countrycodes=br` cumpre o papel do `components=country:BR` do Google.
        $results = $this->request('search', ['q' => $address, 'countrycodes' => 'br', 'limit' => 1], ['address' => $address]);
        $first = is_array($results) ? ($results[0] ?? null) : null;

        if (! isset($first['lat'], $first['lon'])) {
            return null;
        }

        return ['latitude' => (float) $first['lat'], 'longitude' => (float) $first['lon']];
    }

    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $result = $this->request(
            'reverse',
            ['lat' => $latitude, 'lon' => $longitude, 'addressdetails' => 1],
            ['latitude' => $latitude, 'longitude' => $longitude]
        );

        // Ponto sem endereço (oceano) volta 200 com `{"error": "Unable to geocode"}`.
        if (! is_array($result) || ! is_array($result['address'] ?? null)) {
            return null;
        }

        return $this->parseAddress($result);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $logContext
     * @return array<mixed>|null
     */
    private function request(string $endpoint, array $query, array $logContext): ?array
    {
        try {
            $response = $this->waitForTurn(fn (): Response => Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent($this->userAgent())
                ->get(rtrim($this->baseUrl(), '/').'/'.$endpoint, [
                    ...$query,
                    'format' => 'jsonv2',
                    'accept-language' => 'pt-BR',
                    'email' => config('services.nominatim.email') ?: null,
                ]));
        } catch (Throwable $exception) {
            Log::error('NominatimGeocodingProvider: exception during request', [...$logContext, 'error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::info('NominatimGeocodingProvider: request failed', [...$logContext, 'http_status' => $response->status()]);

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * Garante o teto de 1 req/s entre TODOS os processos (workers de fila inclusive): o lock
     * não é liberado de propósito — ele expira sozinho no fim do intervalo, e a próxima
     * requisição só sai depois disso. Intervalo 0 desliga (suíte de testes).
     *
     * @param  callable(): Response  $send
     */
    private function waitForTurn(callable $send): Response
    {
        $interval = (int) config('services.nominatim.min_interval_seconds', 1);

        if ($interval > 0) {
            Cache::store(config('services.geocoding.cache_store'))
                ->lock(self::RATE_LIMIT_LOCK, $interval)
                ->block(self::RATE_LIMIT_WAIT_SECONDS);
        }

        return $send();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string|null>
     */
    private function parseAddress(array $result): array
    {
        $address = $result['address'];
        $components = array_map(
            fn (array $keys): ?string => $this->firstPresent($address, $keys),
            self::COMPONENT_KEYS
        );
        $stateCode = $address['ISO3166-2-lvl4'] ?? null;

        return [
            'formatted_address' => $result['display_name'] ?? null,
            ...$components,
            'state_short' => is_string($stateCode) && str_starts_with($stateCode, self::STATE_CODE_PREFIX)
                ? substr($stateCode, strlen(self::STATE_CODE_PREFIX))
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  list<string>  $keys
     */
    private function firstPresent(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($address[$key] ?? null) && trim($address[$key]) !== '') {
                return $address[$key];
            }
        }

        return null;
    }

    private function baseUrl(): string
    {
        return (string) config('services.nominatim.base_url', '');
    }

    private function userAgent(): string
    {
        return (string) config('services.nominatim.user_agent', '');
    }
}
