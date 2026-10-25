<?php

namespace Tests\Feature;

use App\Contracts\GeocodingProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GEOCODING_NOMINATIM_ENABLED=true` sem chave do Google: o mesmo contrato de `GET /api/public/reverse-geocode` (ver
 * `ReverseGeocodeTest`), respondido pelo OpenStreetMap em vez do Google.
 *
 * Coordenadas diferentes por teste pelo mesmo motivo de `ReverseGeocodeTest`: o cache de
 * `GeocodingService` é por coordenada.
 */
class NominatimGeocodingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.geocoding.providers.nominatim.enabled' => true,
            'services.google.maps_api_key' => '',
        ]);
    }

    public function test_it_resolves_the_address_without_a_google_key(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->reversePayload())]);

        $this->getJson('/api/public/reverse-geocode?latitude=-23.5611&longitude=-46.6559')
            ->assertOk()
            ->assertJson([
                'resolved' => true,
                'status' => 'ok',
                'address' => [
                    'formatted' => 'Avenida Paulista, 1578, Bela Vista, São Paulo, SP, 01310-200, Brasil',
                    'street' => 'Avenida Paulista',
                    'number' => '1578',
                    'neighborhood' => 'Bela Vista',
                    'city' => 'São Paulo',
                    'state' => 'São Paulo',
                    'state_code' => 'SP',
                    'zip_code' => '01310-200',
                    'country' => 'Brasil',
                ],
            ]);
    }

    /** Política de uso da instância pública: sem User-Agent identificado, o OSM bloqueia. */
    public function test_it_identifies_the_application_and_asks_for_portuguese(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->reversePayload())]);

        $this->getJson('/api/public/reverse-geocode?latitude=-23.5612&longitude=-46.6558')->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reverse')
            && str_starts_with($request->header('User-Agent')[0] ?? '', '2pets/')
            && $request['accept-language'] === 'pt-BR'
            && $request['format'] === 'jsonv2');
    }

    /** Cidade pequena: o OSM põe o município em `town` e o bairro em `neighbourhood`. */
    public function test_it_falls_back_to_the_alternative_osm_keys(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Centro, Serra Negra, SP, Brasil',
            'address' => ['neighbourhood' => 'Centro', 'town' => 'Serra Negra', 'ISO3166-2-lvl4' => 'BR-SP'],
        ])]);

        $this->getJson('/api/public/reverse-geocode?latitude=-22.6120&longitude=-46.7010')
            ->assertOk()
            ->assertJson(['address' => ['neighborhood' => 'Centro', 'city' => 'Serra Negra', 'state_code' => 'SP', 'street' => null]]);
    }

    /** Ponto sem endereço: o Nominatim responde 200 com `error`, e isso é `not_found`. */
    public function test_it_reports_not_found_when_nominatim_has_no_address(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['error' => 'Unable to geocode'])]);

        $this->getJson('/api/public/reverse-geocode?latitude=-30.1235&longitude=-40.5679')
            ->assertOk()
            ->assertJson(['resolved' => false, 'status' => 'not_found', 'address' => null]);
    }

    public function test_a_nominatim_failure_does_not_become_a_server_error(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

        $this->getJson('/api/public/reverse-geocode?latitude=-22.9098&longitude=-47.0627')
            ->assertOk()
            ->assertJson(['resolved' => false, 'status' => 'not_found']);
    }

    public function test_it_geocodes_an_address_restricted_to_brazil(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '-21.7878', 'lon' => '-46.5613']])]);

        $coordinates = app(GeocodingProvider::class)->geocode('Rua Assis Figueiredo, Poços de Caldas - MG');

        $this->assertSame(['latitude' => -21.7878, 'longitude' => -46.5613], $coordinates);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search')
            && $request['countrycodes'] === 'br');
    }

    public function test_an_address_without_results_geocodes_to_null(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $this->assertNull(app(GeocodingProvider::class)->geocode('Endereço que não existe'));
    }

    /**
     * @return array<string, mixed>
     */
    private function reversePayload(): array
    {
        return [
            'display_name' => 'Avenida Paulista, 1578, Bela Vista, São Paulo, SP, 01310-200, Brasil',
            'address' => [
                'house_number' => '1578',
                'road' => 'Avenida Paulista',
                'suburb' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'São Paulo',
                'ISO3166-2-lvl4' => 'BR-SP',
                'postcode' => '01310-200',
                'country' => 'Brasil',
                'country_code' => 'br',
            ],
        ];
    }
}
