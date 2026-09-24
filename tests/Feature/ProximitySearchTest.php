<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Busca por proximidade (`GET /api/public/search` e `/nearby`): ordenação por distância,
 * raio de atendimento do VOLANTE, busca por CEP e o que a API pública expõe da localização.
 *
 * Geografia dos cenários — origem no centro de Poços de Caldas; 0,009° de latitude ≈ 1 km:
 * clínica a ~1 km, clínica a ~3 km, vet volante a ~4 km (raio 10 km), vet volante a ~8 km
 * (raio 5 km — o tutor está FORA da área dele) e clínica a ~20 km com raio 5 km (ponto fixo:
 * o raio não a limita).
 */
class ProximitySearchTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LATITUDE = -21.7878;

    private const ORIGIN_LONGITUDE = -46.5613;

    private const DEGREES_PER_KM = 0.009;

    public function test_results_are_ordered_by_distance_and_carry_the_distance_in_meters(): void
    {
        $far = $this->createProfessionalAt(3, ['professional_type' => 'clinic']);
        $near = $this->createProfessionalAt(1, ['professional_type' => 'clinic']);

        $response = $this->getJson($this->searchUrl())->assertOk();

        $this->assertSame([$near->id, $far->id], array_column($response->json('data'), 'id'));
        $this->assertEqualsWithDelta(1000, $response->json('data.0.distance_m'), 20);
        $this->assertEqualsWithDelta(3000, $response->json('data.1.distance_m'), 40);
        $this->assertIsInt($response->json('data.0.distance_m'));
    }

    public function test_mobile_professional_is_excluded_when_the_tutor_is_outside_its_service_radius(): void
    {
        $outOfReach = $this->createProfessionalAt(8, ['professional_type' => 'vet', 'service_radius_km' => 5]);
        $inReach = $this->createProfessionalAt(4, ['professional_type' => 'vet', 'service_radius_km' => 10]);

        $ids = array_column($this->getJson($this->searchUrl())->assertOk()->json('data'), 'id');

        $this->assertContains($inReach->id, $ids);
        $this->assertNotContains($outOfReach->id, $ids);
    }

    public function test_mobile_professional_without_service_radius_is_not_filtered_out(): void
    {
        $withoutRadius = $this->createProfessionalAt(8, ['professional_type' => 'vet', 'service_radius_km' => null]);

        $ids = array_column($this->getJson($this->searchUrl())->assertOk()->json('data'), 'id');

        $this->assertContains($withoutRadius->id, $ids);
    }

    public function test_fixed_location_professional_is_not_limited_by_its_service_radius(): void
    {
        $clinic = $this->createProfessionalAt(20, ['professional_type' => 'clinic', 'service_radius_km' => 5]);

        $ids = array_column($this->getJson($this->searchUrl())->assertOk()->json('data'), 'id');

        $this->assertContains($clinic->id, $ids);
    }

    public function test_search_radius_still_limits_every_professional(): void
    {
        $clinic = $this->createProfessionalAt(20, ['professional_type' => 'clinic']);

        $ids = array_column($this->getJson($this->searchUrl(['radius_km' => 10]))->assertOk()->json('data'), 'id');

        $this->assertNotContains($clinic->id, $ids);
    }

    public function test_search_by_zip_code_resolves_the_origin_in_the_backend(): void
    {
        $this->fakeZipCodeResolution('37701001', self::ORIGIN_LATITUDE, self::ORIGIN_LONGITUDE);
        $far = $this->createProfessionalAt(3, ['professional_type' => 'clinic']);
        $near = $this->createProfessionalAt(1, ['professional_type' => 'clinic']);

        $response = $this->getJson('/api/public/search?zip_code=37701-001')->assertOk();

        $this->assertSame([$near->id, $far->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.origin.source', 'zip_code')
            ->assertJsonPath('meta.origin.zip_code', '37701001')
            ->assertJsonPath('meta.origin.label', 'Centro, Poços de Caldas');
    }

    public function test_search_by_zip_code_respects_the_mobile_service_radius(): void
    {
        $this->fakeZipCodeResolution('37701002', self::ORIGIN_LATITUDE, self::ORIGIN_LONGITUDE);
        $outOfReach = $this->createProfessionalAt(8, ['professional_type' => 'vet', 'service_radius_km' => 5]);

        $ids = array_column($this->getJson('/api/public/search?zip_code=37701002')->assertOk()->json('data'), 'id');

        $this->assertNotContains($outOfReach->id, $ids);
    }

    public function test_explicit_coordinates_win_over_zip_code_without_calling_any_provider(): void
    {
        Http::fake();
        $this->createProfessionalAt(1, ['professional_type' => 'clinic']);

        $this->getJson($this->searchUrl(['zip_code' => '37701003']))
            ->assertOk()
            ->assertJsonMissingPath('meta.origin');

        Http::assertNothingSent();
    }

    public function test_search_by_unknown_zip_code_returns_422(): void
    {
        config(['services.google.maps_api_key' => 'chave-de-teste']);
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true])]);

        $this->getJson('/api/public/search?zip_code=99999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('zip_code');
    }

    public function test_search_by_malformed_zip_code_returns_422(): void
    {
        $this->getJson('/api/public/search?zip_code=123')
            ->assertStatus(422)
            ->assertJsonValidationErrors('zip_code');
    }

    public function test_search_by_zip_code_returns_503_when_geocoding_is_not_configured(): void
    {
        config(['services.google.maps_api_key' => '']);
        Http::fake(['viacep.com.br/*' => Http::response($this->viaCepPayload('37701004'))]);

        $this->getJson('/api/public/search?zip_code=37701004')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'geocoding_unavailable');
    }

    public function test_nearby_accepts_zip_code_and_requires_some_origin(): void
    {
        $this->fakeZipCodeResolution('37701005', self::ORIGIN_LATITUDE, self::ORIGIN_LONGITUDE);
        $near = $this->createProfessionalAt(1, ['professional_type' => 'clinic']);

        $this->getJson('/api/public/nearby?zip_code=37701005')
            ->assertOk()
            ->assertJsonPath('data.0.id', $near->id);

        $this->getJson('/api/public/nearby')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_public_card_of_a_mobile_professional_hides_street_and_blurs_location(): void
    {
        $vet = $this->createProfessionalAt(4, ['professional_type' => 'vet', 'service_radius_km' => 10], [
            'address' => 'Rua Residencial Secreta',
            'number' => '321',
            'neighborhood' => 'Jardim Quisisana',
        ]);

        $response = $this->getJson($this->searchUrl())->assertOk();
        $card = collect($response->json('data'))->firstWhere('id', $vet->id);

        $this->assertNull($card['address']);
        $this->assertTrue($card['is_mobile']);
        $this->assertSame('Jardim Quisisana', $card['neighborhood']);
        $this->assertSame(0, $card['distance_m'] % 500);
        $this->assertSame(round((float) $vet->latitude, 2), $card['latitude']);
        $this->assertStringNotContainsString('Residencial Secreta', $response->getContent());
    }

    public function test_public_card_of_a_fixed_location_professional_keeps_the_commercial_address(): void
    {
        $clinic = $this->createProfessionalAt(1, ['professional_type' => 'clinic'], [
            'address' => 'Rua Assis Figueiredo',
            'number' => '100',
            'neighborhood' => 'Centro',
        ]);

        $card = collect($this->getJson($this->searchUrl())->assertOk()->json('data'))->firstWhere('id', $clinic->id);

        $this->assertSame('Rua Assis Figueiredo, 100, Centro', $card['address']);
        $this->assertFalse($card['is_mobile']);
    }

    public function test_public_profile_of_a_mobile_professional_hides_street(): void
    {
        $vet = $this->createProfessionalAt(4, ['professional_type' => 'vet'], ['address' => 'Rua Residencial Oculta']);

        $response = $this->getJson("/api/public/professionals/{$vet->id}")->assertOk();

        $this->assertStringNotContainsString('Residencial Oculta', $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $professionalOverrides
     * @param  array<string, mixed>  $userOverrides
     */
    private function createProfessionalAt(float $kilometersNorth, array $professionalOverrides, array $userOverrides = []): User
    {
        $user = User::factory()->professional()->create([
            'latitude' => self::ORIGIN_LATITUDE + $kilometersNorth * self::DEGREES_PER_KM,
            'longitude' => self::ORIGIN_LONGITUDE,
            'city' => 'Poços de Caldas',
            'state' => 'MG',
            ...$userOverrides,
        ]);

        Professional::factory()->create(['user_id' => $user->id, ...$professionalOverrides]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function searchUrl(array $extra = []): string
    {
        return '/api/public/search?'.http_build_query([
            'latitude' => self::ORIGIN_LATITUDE,
            'longitude' => self::ORIGIN_LONGITUDE,
            'per_page' => 50,
            ...$extra,
        ]);
    }

    private function fakeZipCodeResolution(string $zipCode, float $latitude, float $longitude): void
    {
        config(['services.google.maps_api_key' => 'chave-de-teste']);

        Http::fake([
            'viacep.com.br/*' => Http::response($this->viaCepPayload($zipCode)),
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => ['lat' => $latitude, 'lng' => $longitude]]]],
            ]),
        ]);
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
}
