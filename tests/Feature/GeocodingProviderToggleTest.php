<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Liga/desliga dos serviços de mapas (`services.geocoding.providers`) e a prioridade entre
 * eles: Google primeiro, Nominatim como fallback — ver `ChainGeocodingProvider`.
 *
 * Coordenadas diferentes por teste: o cache de `GeocodingService` é por coordenada.
 */
class GeocodingProviderToggleTest extends TestCase
{
    public function test_google_has_priority_when_both_are_enabled_and_configured(): void
    {
        $this->enable(google: true, nominatim: true);
        Http::fake([
            'maps.googleapis.com/*' => Http::response($this->googlePayload('Bela Vista')),
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimPayload('Morro dos Ingleses')),
        ]);

        $this->reverseGeocode('-23.5701', '-46.6501')
            ->assertJson(['resolved' => true, 'address' => ['neighborhood' => 'Bela Vista']]);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'nominatim'));
    }

    public function test_nominatim_answers_when_google_has_no_result(): void
    {
        $this->enable(google: true, nominatim: true);
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimPayload('Morro dos Ingleses')),
        ]);

        $this->reverseGeocode('-23.5702', '-46.6502')
            ->assertJson(['resolved' => true, 'address' => ['neighborhood' => 'Morro dos Ingleses']]);
    }

    public function test_an_active_google_without_key_is_skipped_without_a_request(): void
    {
        $this->enable(google: true, nominatim: true, googleKey: '');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->nominatimPayload('Centro'))]);

        $this->reverseGeocode('-23.5703', '-46.6503')->assertJson(['resolved' => true]);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis'));
    }

    public function test_a_disabled_google_is_not_called_even_with_a_key(): void
    {
        $this->enable(google: false, nominatim: true);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->nominatimPayload('Centro'))]);

        $this->reverseGeocode('-23.5704', '-46.6504')->assertJson(['resolved' => true]);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis'));
    }

    public function test_everything_disabled_reports_unavailable_without_requests(): void
    {
        $this->enable(google: false, nominatim: false);
        Http::fake();

        $this->reverseGeocode('-23.5705', '-46.6505')
            ->assertJson(['resolved' => false, 'status' => 'unavailable', 'address' => null]);

        Http::assertNothingSent();
    }

    private function enable(bool $google, bool $nominatim, string $googleKey = 'chave-de-teste'): void
    {
        config([
            'services.geocoding.providers.google.enabled' => $google,
            'services.geocoding.providers.nominatim.enabled' => $nominatim,
            'services.google.maps_api_key' => $googleKey,
        ]);
    }

    private function reverseGeocode(string $latitude, string $longitude): TestResponse
    {
        return $this->getJson("/api/public/reverse-geocode?latitude={$latitude}&longitude={$longitude}")->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function googlePayload(string $neighborhood): array
    {
        return [
            'status' => 'OK',
            'results' => [[
                'formatted_address' => "{$neighborhood}, São Paulo - SP",
                'address_components' => [
                    ['long_name' => $neighborhood, 'short_name' => $neighborhood, 'types' => ['sublocality_level_1']],
                    ['long_name' => 'São Paulo', 'short_name' => 'São Paulo', 'types' => ['administrative_area_level_2']],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nominatimPayload(string $neighborhood): array
    {
        return [
            'display_name' => "{$neighborhood}, São Paulo, Brasil",
            'address' => ['suburb' => $neighborhood, 'city' => 'São Paulo', 'ISO3166-2-lvl4' => 'BR-SP'],
        ];
    }
}
