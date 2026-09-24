<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GET /api/public/reverse-geocode` — coordenada → endereço para a modal de confirmação.
 *
 * O contrato que o frontend depende: HTTP 200 para coordenada VÁLIDA, sempre, com `resolved`
 * dizendo se deu certo e `status` dizendo por que não. Nunca 5xx — "não há endereço neste
 * ponto" e "não consigo consultar agora" são respostas, não falhas.
 *
 * Cada teste usa uma coordenada diferente: `GeocodingService` cacheia por coordenada (na
 * suíte, no store `array` — `GEOCODING_CACHE_STORE` do `phpunit.xml`), e chaves distintas
 * mantêm os testes independentes mesmo dentro de um único processo.
 */
class ReverseGeocodeTest extends TestCase
{
    /**
     * O caminho que roda HOJE: a `GOOGLE_MAPS_API_KEY` ainda não foi configurada.
     * Tem que ser 200 com `status: unavailable` — e nenhuma requisição ao Google, porque sem
     * chave ela só voltaria `REQUEST_DENIED` gastando latência e log.
     */
    public function test_it_reports_unavailable_when_the_api_key_is_missing(): void
    {
        config(['services.google.maps_api_key' => '']);
        Http::fake();

        $response = $this->getJson('/api/public/reverse-geocode?latitude=-23.5505&longitude=-46.6333');

        $response->assertOk()->assertJson([
            'resolved' => false,
            'status' => 'unavailable',
            'latitude' => -23.5505,
            'longitude' => -46.6333,
            'address' => null,
        ]);

        Http::assertNothingSent();
    }

    public function test_it_returns_the_address_components_when_the_provider_answers(): void
    {
        config(['services.google.maps_api_key' => 'chave-de-teste']);
        Http::fake(['maps.googleapis.com/*' => Http::response($this->googleSuccessPayload())]);

        $response = $this->getJson('/api/public/reverse-geocode?latitude=-23.5610&longitude=-46.6560');

        $response->assertOk()->assertJson([
            'resolved' => true,
            'status' => 'ok',
            'address' => [
                'formatted' => 'Av. Paulista, 1578 - Bela Vista, São Paulo - SP, 01310-200',
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

    /**
     * Coordenada no meio do oceano: o provedor respondeu, simplesmente não há endereço. É
     * `not_found`, e o frontend precisa distingui-lo de `unavailable` — num o endereço não
     * existe, no outro a consulta é que falhou.
     */
    public function test_it_reports_not_found_when_the_provider_has_no_result(): void
    {
        config(['services.google.maps_api_key' => 'chave-de-teste']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

        $this->getJson('/api/public/reverse-geocode?latitude=-30.1234&longitude=-40.5678')
            ->assertOk()
            ->assertJson(['resolved' => false, 'status' => 'not_found', 'address' => null]);
    }

    /**
     * Provedor fora do ar não pode virar 500 na cara do usuário.
     *
     * ⚠️ Hoje ele cai em `not_found`, e não em `unavailable`. Isso é imprecisão CONHECIDA e
     * registrada como dívida, não descuido: `GeocodingService::reverseGeocode()` devolve
     * `null` tanto para "não achei" quanto para "falhei", e separar os dois muda o contrato de
     * um método com outros consumidores. O efeito prático é nulo por enquanto — o frontend cai
     * no mesmo fallback nos dois casos —, mas vira relevante se um dia houver retry
     * automático, que só faz sentido para `unavailable`.
     */
    public function test_a_provider_failure_does_not_become_a_server_error(): void
    {
        config(['services.google.maps_api_key' => 'chave-de-teste']);
        Http::fake(['maps.googleapis.com/*' => Http::response('', 503)]);

        $this->getJson('/api/public/reverse-geocode?latitude=-22.9099&longitude=-47.0626')
            ->assertOk()
            ->assertJson(['resolved' => false, 'status' => 'not_found']);
    }

    /**
     * @dataProvider invalidCoordinateProvider
     */
    public function test_it_rejects_coordinates_outside_the_planet(string $queryString, string $invalidField): void
    {
        $this->getJson('/api/public/reverse-geocode?'.$queryString)
            ->assertStatus(422)
            ->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidCoordinateProvider(): array
    {
        return [
            'latitude acima de 90' => ['latitude=999&longitude=-46.63', 'latitude'],
            'longitude abaixo de -180' => ['latitude=-23.55&longitude=-999', 'longitude'],
            'latitude ausente' => ['longitude=-46.63', 'latitude'],
            'longitude ausente' => ['latitude=-23.55', 'longitude'],
            'latitude não numérica' => ['latitude=aqui&longitude=-46.63', 'latitude'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function googleSuccessPayload(): array
    {
        return [
            'status' => 'OK',
            'results' => [[
                'formatted_address' => 'Av. Paulista, 1578 - Bela Vista, São Paulo - SP, 01310-200',
                'address_components' => [
                    ['long_name' => '1578', 'short_name' => '1578', 'types' => ['street_number']],
                    ['long_name' => 'Avenida Paulista', 'short_name' => 'Av. Paulista', 'types' => ['route']],
                    ['long_name' => 'Bela Vista', 'short_name' => 'Bela Vista', 'types' => ['sublocality_level_1', 'sublocality']],
                    ['long_name' => 'São Paulo', 'short_name' => 'São Paulo', 'types' => ['administrative_area_level_2']],
                    ['long_name' => 'São Paulo', 'short_name' => 'SP', 'types' => ['administrative_area_level_1']],
                    ['long_name' => 'Brasil', 'short_name' => 'BR', 'types' => ['country']],
                    ['long_name' => '01310-200', 'short_name' => '01310-200', 'types' => ['postal_code']],
                ],
            ]],
        ];
    }
}
