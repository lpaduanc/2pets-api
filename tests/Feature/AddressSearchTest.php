<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `GET /api/public/address-search` — autocomplete de endereço do seletor de mapa.
 *
 * Contrato: 200 para texto válido, sempre, com `status` + `data`; cada item com coordenada;
 * só Brasil. Cadeia Google Places → Photon pela ordem de `services.geocoding.providers`.
 */
class AddressSearchTest extends TestCase
{
    private const PLACES_URL = 'places.googleapis.com/*';

    private const PHOTON_URL = 'photon.komoot.io/*';

    public function test_google_places_answers_with_the_full_contract(): void
    {
        $this->enable(google: true, photon: false);
        Http::fake([self::PLACES_URL => Http::response(['places' => [$this->googlePlace()]])]);

        $this->search('q=rua das acacias 120')->assertOk()->assertExactJson([
            'status' => 'ok',
            'data' => [[
                'id' => 'google:ChIJ-acacias',
                'title' => 'Rua das Acácias, 120',
                'subtitle' => 'Vila Mariana, São Paulo - SP',
                'formatted' => 'R. das Acácias, 120 - Vila Mariana, São Paulo - SP, 04101-000, Brasil',
                'street' => 'Rua das Acácias',
                'number' => '120',
                'neighborhood' => 'Vila Mariana',
                'city' => 'São Paulo',
                'state' => 'São Paulo',
                'state_code' => 'SP',
                'zip_code' => '04101-000',
                'latitude' => -23.5891,
                'longitude' => -46.6342,
            ]],
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('X-Goog-Api-Key', 'chave-de-teste')
            && str_contains($request->header('X-Goog-FieldMask')[0], 'places.location')
            && $request['regionCode'] === 'BR'
            && $request['languageCode'] === 'pt-BR'
            && $request['pageSize'] === 10
            && ! isset($request['locationBias']));
    }

    public function test_google_receives_the_location_bias(): void
    {
        $this->enable(google: true, photon: false);
        Http::fake([self::PLACES_URL => Http::response(['places' => [$this->googlePlace()]])]);

        $this->search('q=rua das acacias&latitude=-21.78&longitude=-46.56&limit=3')->assertOk();

        Http::assertSent(fn (Request $request): bool => $request['locationBias']['circle']['center'] === ['latitude' => -21.78, 'longitude' => -46.56]
            && $request['pageSize'] === 6);
    }

    public function test_google_results_outside_brazil_or_without_coordinates_are_dropped(): void
    {
        $this->enable(google: true, photon: false);
        $abroad = $this->googlePlace(id: 'abroad', country: 'PT');
        $withoutLocation = [...$this->googlePlace(id: 'nowhere'), 'location' => null];
        Http::fake([self::PLACES_URL => Http::response(['places' => [$abroad, $withoutLocation, $this->googlePlace()]])]);

        $this->search('q=rua das acacias lisboa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'google:ChIJ-acacias');
    }

    public function test_photon_answers_when_google_has_no_key(): void
    {
        $this->enable(google: true, photon: true, googleKey: '');
        Http::fake([self::PHOTON_URL => Http::response($this->photonCollection([$this->photonStreet()]))]);

        $this->search('q=rua assis figueiredo pocos')->assertOk()->assertJson([
            'status' => 'ok',
            'data' => [[
                'id' => 'osm:W:832562652',
                'title' => 'Rua Assis Figueiredo',
                'subtitle' => 'Centro, Poços de Caldas - MG',
                'street' => 'Rua Assis Figueiredo',
                'number' => null,
                'neighborhood' => 'Centro',
                'city' => 'Poços de Caldas',
                'state' => 'Minas Gerais',
                'state_code' => 'MG',
                'zip_code' => '37701-013',
                'latitude' => -21.7856824,
                'longitude' => -46.5658061,
            ]],
        ]);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent')
            && str_contains($request->header('User-Agent')[0], '2pets')
            && isset($request['bbox'])
            && ! isset($request['lat']));
    }

    public function test_photon_receives_the_location_bias(): void
    {
        $this->enable(google: false, photon: true);
        Http::fake([self::PHOTON_URL => Http::response($this->photonCollection([$this->photonStreet()]))]);

        $this->search('q=rua assis figueiredo&latitude=-21.78&longitude=-46.56')->assertOk();

        Http::assertSent(fn (Request $request): bool => (float) $request['lat'] === -21.78 && (float) $request['lon'] === -46.56);
    }

    public function test_photon_results_outside_brazil_or_without_coordinates_are_dropped(): void
    {
        $this->enable(google: false, photon: true);
        $abroad = $this->photonStreet(osmId: 1, countryCode: 'PY');
        $withoutGeometry = [...$this->photonStreet(osmId: 2), 'geometry' => null];
        Http::fake([self::PHOTON_URL => Http::response($this->photonCollection([$abroad, $withoutGeometry, $this->photonStreet()]))]);

        $this->search('q=rua assis figueiredo fronteira')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'osm:W:832562652');
    }

    /**
     * O Photon devolve um item por trecho (way) de rua do OSM: cinco trechos da mesma rua no
     * mesmo bairro são UMA sugestão. O primeiro fica, e o pedido ao provedor é o dobro do
     * `limit` para ainda sobrarem `limit` endereços distintos.
     */
    public function test_identical_suggestions_are_merged_before_the_limit(): void
    {
        $this->enable(google: false, photon: true);
        $sameStreet = array_map(fn (int $osmId): array => $this->photonStreet(osmId: $osmId), [11, 12, 13, 14, 15]);
        $otherDistrict = $this->photonStreet(osmId: 16, district: 'Região Urbana Homogênea X');
        $thirdDistrict = $this->photonStreet(osmId: 17, district: 'Jardim dos Estados');
        Http::fake([self::PHOTON_URL => Http::response($this->photonCollection([...$sameStreet, $otherDistrict, $thirdDistrict]))]);

        $this->search('q=rua assis figueiredo trechos&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'osm:W:11')
            ->assertJsonPath('data.1.id', 'osm:W:16');

        Http::assertSent(fn (Request $request): bool => (int) $request['limit'] >= 4);
    }

    public function test_google_has_priority_when_both_answer(): void
    {
        $this->enable(google: true, photon: true);
        Http::fake([
            self::PLACES_URL => Http::response(['places' => [$this->googlePlace()]]),
            self::PHOTON_URL => Http::response($this->photonCollection([$this->photonStreet()])),
        ]);

        $this->search('q=rua das acacias prioridade')->assertJsonPath('data.0.id', 'google:ChIJ-acacias');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'photon'));
    }

    public function test_photon_answers_when_google_finds_nothing(): void
    {
        $this->enable(google: true, photon: true);
        Http::fake([
            self::PLACES_URL => Http::response([]),
            self::PHOTON_URL => Http::response($this->photonCollection([$this->photonStreet()])),
        ]);

        $this->search('q=rua assis figueiredo fallback')->assertJsonPath('data.0.id', 'osm:W:832562652');
    }

    public function test_a_disabled_google_is_not_called_even_with_a_key(): void
    {
        $this->enable(google: false, photon: true);
        Http::fake([self::PHOTON_URL => Http::response($this->photonCollection([$this->photonStreet()]))]);

        $this->search('q=rua assis figueiredo desligado')->assertJsonPath('status', 'ok');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis'));
    }

    public function test_nothing_configured_reports_unavailable_without_requests(): void
    {
        $this->enable(google: true, photon: false, googleKey: '');
        Http::fake();

        $this->search('q=rua sem provedor')->assertOk()->assertExactJson(['status' => 'unavailable', 'data' => []]);

        Http::assertNothingSent();
    }

    public function test_provider_failures_report_unavailable_not_a_server_error(): void
    {
        $this->enable(google: true, photon: true);
        Http::fake([self::PLACES_URL => Http::response([], 500), self::PHOTON_URL => Http::response('', 503)]);

        $this->search('q=rua tudo fora do ar')->assertOk()->assertExactJson(['status' => 'unavailable', 'data' => []]);
    }

    public function test_an_answer_without_matches_reports_not_found(): void
    {
        $this->enable(google: true, photon: true);
        Http::fake([self::PLACES_URL => Http::response([], 500), self::PHOTON_URL => Http::response($this->photonCollection([]))]);

        $this->search('q=xqzxqzxqz')->assertOk()->assertExactJson(['status' => 'not_found', 'data' => []]);
    }

    public function test_a_repeated_search_is_served_from_cache(): void
    {
        $this->enable(google: false, photon: true);
        Http::fake([self::PHOTON_URL => Http::response($this->photonCollection([$this->photonStreet()]))]);

        $this->search('q=Rua Assis  Figueiredo&latitude=-21.7812&longitude=-46.5611')->assertJsonPath('status', 'ok');
        $this->search('q=rua assis figueiredo&latitude=-21.7834&longitude=-46.5598')->assertJsonPath('status', 'ok');

        Http::assertSentCount(1);
    }

    public function test_an_empty_answer_is_not_cached(): void
    {
        $this->enable(google: false, photon: true);
        Http::fake([self::PHOTON_URL => Http::sequence()
            ->push($this->photonCollection([]))
            ->push($this->photonCollection([$this->photonStreet()]))]);

        $this->search('q=rua ainda nao mapeada')->assertJsonPath('status', 'not_found');
        $this->search('q=rua ainda nao mapeada')->assertJsonPath('status', 'ok');
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_it_rejects_an_invalid_query(string $queryString, string $invalidField): void
    {
        Http::fake();

        $this->search($queryString)->assertStatus(422)->assertJsonValidationErrors([$invalidField]);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'sem texto' => ['limit=3', 'q'],
            'texto curto' => ['q=ru', 'q'],
            'texto longo' => ['q='.str_repeat('a', 201), 'q'],
            'limite acima de 8' => ['q=rua das acacias&limit=9', 'limit'],
            'limite zero' => ['q=rua das acacias&limit=0', 'limit'],
            'latitude sem longitude' => ['q=rua das acacias&latitude=-21.78', 'longitude'],
            'latitude fora do planeta' => ['q=rua das acacias&latitude=-91&longitude=-46.5', 'latitude'],
        ];
    }

    public function test_it_is_throttled_at_60_requests_per_minute(): void
    {
        $this->enable(google: false, photon: false);

        foreach (range(1, 60) as $attempt) {
            $this->search("q=rua numero {$attempt}")->assertOk();
        }

        $this->search('q=rua numero 61')->assertTooManyRequests();
    }

    private function search(string $queryString): TestResponse
    {
        return $this->getJson('/api/public/address-search?'.str_replace(' ', '%20', $queryString));
    }

    private function enable(bool $google, bool $photon, string $googleKey = 'chave-de-teste'): void
    {
        config([
            'services.geocoding.providers.google.enabled' => $google,
            'services.geocoding.providers.photon.enabled' => $photon,
            'services.google.maps_api_key' => $googleKey,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function googlePlace(string $id = 'ChIJ-acacias', string $country = 'BR'): array
    {
        return [
            'id' => $id,
            'formattedAddress' => 'R. das Acácias, 120 - Vila Mariana, São Paulo - SP, 04101-000, Brasil',
            'location' => ['latitude' => -23.5891, 'longitude' => -46.6342],
            'addressComponents' => [
                ['longText' => '120', 'shortText' => '120', 'types' => ['street_number']],
                ['longText' => 'Rua das Acácias', 'shortText' => 'R. das Acácias', 'types' => ['route']],
                ['longText' => 'Vila Mariana', 'shortText' => 'Vila Mariana', 'types' => ['sublocality_level_1', 'sublocality', 'political']],
                ['longText' => 'São Paulo', 'shortText' => 'São Paulo', 'types' => ['administrative_area_level_2', 'political']],
                ['longText' => 'São Paulo', 'shortText' => 'SP', 'types' => ['administrative_area_level_1', 'political']],
                ['longText' => 'Brasil', 'shortText' => $country, 'types' => ['country', 'political']],
                ['longText' => '04101-000', 'shortText' => '04101-000', 'types' => ['postal_code']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function photonStreet(int $osmId = 832562652, string $countryCode = 'BR', string $district = 'Centro'): array
    {
        return [
            'type' => 'Feature',
            'properties' => [
                'osm_type' => 'W', 'osm_id' => $osmId, 'osm_key' => 'highway', 'osm_value' => 'tertiary',
                'type' => 'street', 'name' => 'Rua Assis Figueiredo', 'district' => $district,
                'city' => 'Poços de Caldas', 'state' => 'Minas Gerais', 'country' => 'Brasil',
                'postcode' => '37701-013', 'countrycode' => $countryCode,
            ],
            'geometry' => ['type' => 'Point', 'coordinates' => [-46.5658061, -21.7856824]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return array<string, mixed>
     */
    private function photonCollection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
