<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GET /api/public/postal-code/{zipCode}` — fallback de localização da busca: CEP → ViaCEP →
 * geocoding → coordenada + "bairro, cidade".
 *
 * Contrato que o frontend depende: 200 com a origem resolvida; 422 no campo `zip_code` para
 * CEP mal formado ou inexistente; 503 com `reason` quando a consulta está indisponível.
 */
class PostalCodeLookupTest extends TestCase
{
    public function test_it_resolves_a_zip_code_to_coordinates_and_a_short_label(): void
    {
        $this->configureGeocoding();
        Http::fake([
            'viacep.com.br/*' => Http::response($this->viaCepPayload('37701010')),
            'maps.googleapis.com/*' => Http::response($this->googlePayload(-21.7878, -46.5613)),
        ]);

        $this->getJson('/api/public/postal-code/37701-010')
            ->assertOk()
            ->assertJson(['data' => [
                'zip_code' => '37701010',
                'street' => 'Rua Assis Figueiredo',
                'neighborhood' => 'Centro',
                'city' => 'Poços de Caldas',
                'state' => 'MG',
                'latitude' => -21.7878,
                'longitude' => -46.5613,
                'label' => 'Centro, Poços de Caldas',
            ]]);
    }

    public function test_it_geocodes_the_full_address_of_the_zip_code(): void
    {
        $this->configureGeocoding();
        Http::fake([
            'viacep.com.br/*' => Http::response($this->viaCepPayload('37701011')),
            'maps.googleapis.com/*' => Http::response($this->googlePayload(-21.7878, -46.5613)),
        ]);

        $this->getJson('/api/public/postal-code/37701011')->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'maps.googleapis.com')
            && $request['address'] === 'Rua Assis Figueiredo, Centro, Poços de Caldas - MG, 37701-011, Brasil'
            && $request['components'] === 'country:BR');
    }

    public function test_it_falls_back_to_the_city_when_the_zip_code_address_does_not_geocode(): void
    {
        $this->configureGeocoding();
        Http::fake([
            'viacep.com.br/*' => Http::response($this->viaCepPayload('37701012')),
            'maps.googleapis.com/*' => Http::sequence()
                ->push(['status' => 'ZERO_RESULTS', 'results' => []])
                ->push($this->googlePayload(-21.79, -46.57)),
        ]);

        $this->getJson('/api/public/postal-code/37701012')
            ->assertOk()
            ->assertJsonPath('data.latitude', -21.79);
    }

    public function test_it_returns_422_for_a_malformed_zip_code(): void
    {
        Http::fake();

        $this->getJson('/api/public/postal-code/1234')
            ->assertStatus(422)
            ->assertJsonValidationErrors('zip_code');

        Http::assertNothingSent();
    }

    public function test_it_returns_422_when_the_zip_code_does_not_exist(): void
    {
        $this->configureGeocoding();
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => 'true'])]);

        $this->getJson('/api/public/postal-code/99999998')
            ->assertStatus(422)
            ->assertJsonPath('errors.zip_code.0', 'CEP não encontrado.');
    }

    public function test_it_returns_503_when_geocoding_is_not_configured(): void
    {
        config(['services.google.maps_api_key' => '']);
        Http::fake(['viacep.com.br/*' => Http::response($this->viaCepPayload('37701013'))]);

        $this->getJson('/api/public/postal-code/37701013')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'geocoding_unavailable');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'maps.googleapis.com'));
    }

    public function test_it_returns_503_when_viacep_is_down(): void
    {
        $this->configureGeocoding();
        Http::fake(['viacep.com.br/*' => Http::response('', 500)]);

        $this->getJson('/api/public/postal-code/37701014')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'postal_service_unavailable');
    }

    public function test_a_repeated_zip_code_is_served_from_cache(): void
    {
        $this->configureGeocoding();
        Http::fake([
            'viacep.com.br/*' => Http::response($this->viaCepPayload('37701015')),
            'maps.googleapis.com/*' => Http::response($this->googlePayload(-21.7878, -46.5613)),
        ]);

        $this->getJson('/api/public/postal-code/37701015')->assertOk();
        $this->getJson('/api/public/postal-code/37701015')->assertOk();

        Http::assertSentCount(2);
    }

    private function configureGeocoding(): void
    {
        config(['services.google.maps_api_key' => 'chave-de-teste']);
    }

    /**
     * @return array<string, string>
     */
    private function viaCepPayload(string $zipCode): array
    {
        return [
            'cep' => substr($zipCode, 0, 5).'-'.substr($zipCode, 5),
            'logradouro' => 'Rua Assis Figueiredo',
            'bairro' => 'Centro',
            'localidade' => 'Poços de Caldas',
            'uf' => 'MG',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function googlePayload(float $latitude, float $longitude): array
    {
        return ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => $latitude, 'lng' => $longitude]]]]];
    }
}
