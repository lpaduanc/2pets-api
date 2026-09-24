<?php

namespace Tests\Feature;

use App\Enums\Location\GeocodingStatus;
use App\Jobs\GeocodeUserAddress;
use App\Models\User;
use App\Services\Location\UserAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Falha de geocoding no cadastro/edição do endereço: o endereço é salvo mesmo assim, a
 * coordenada é ANULADA (nunca fica o ponto do endereço antigo), o status vira `failed` e o
 * reprocessamento (`GeocodeUserAddress` / `geocoding:retry-failed`) resolve depois.
 */
class AddressGeocodingFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_geocoding_saves_the_address_and_drops_the_stale_point(): void
    {
        $this->configureGeocoding();
        Queue::fake();
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
        $user = $this->professionalWithResolvedAddress();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['address' => 'Rua Que Não Existe', 'city' => 'Poços de Caldas', 'state' => 'MG'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('Rua Que Não Existe', $user->address);
        $this->assertNull($user->latitude);
        $this->assertNull($user->longitude);
        $this->assertSame(GeocodingStatus::FAILED, $user->geocoding_status);
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('location'));
    }

    public function test_failed_geocoding_enqueues_a_retry_when_a_provider_is_configured(): void
    {
        $this->configureGeocoding();
        Queue::fake();
        Http::fake(['maps.googleapis.com/*' => Http::response([], 500)]);
        $user = $this->professionalWithResolvedAddress();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['address' => 'Rua Em Obras', 'city' => 'Poços de Caldas'])->assertOk();

        Queue::assertPushed(GeocodeUserAddress::class);
    }

    public function test_no_retry_is_enqueued_without_a_configured_provider(): void
    {
        config(['services.google.maps_api_key' => '']);
        Queue::fake();
        $user = $this->professionalWithResolvedAddress();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['address' => 'Rua Sem Chave', 'city' => 'Poços de Caldas'])->assertOk();

        $this->assertSame(GeocodingStatus::FAILED, $user->fresh()->geocoding_status);
        Queue::assertNotPushed(GeocodeUserAddress::class);
    }

    public function test_successful_geocoding_marks_the_address_as_resolved(): void
    {
        $this->configureGeocoding();
        $this->fakeGoogleAnswer(-21.80, -46.57);
        $user = $this->professionalWithResolvedAddress();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['address' => 'Rua Nova', 'number' => '10', 'city' => 'Poços de Caldas'])->assertOk();

        $user->refresh();
        $this->assertSame(GeocodingStatus::RESOLVED, $user->geocoding_status);
        $this->assertNotNull($user->geocoded_at);
        $this->assertEqualsWithDelta(-21.80, (float) $user->latitude, 0.0001);
    }

    public function test_the_retry_job_resolves_a_failed_address(): void
    {
        $this->configureGeocoding();
        $this->fakeGoogleAnswer(-21.7878, -46.5613);
        $user = $this->professionalWithFailedAddress();

        (new GeocodeUserAddress($user->id))->handle(app(UserAddressGeocoder::class));

        $user->refresh();
        $this->assertSame(GeocodingStatus::RESOLVED, $user->geocoding_status);
        $this->assertEqualsWithDelta(-21.7878, (float) $user->latitude, 0.0001);
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('location'));
    }

    public function test_the_retry_job_throws_so_the_queue_backs_off_when_it_still_fails(): void
    {
        $this->configureGeocoding();
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
        $user = $this->professionalWithFailedAddress();

        $this->expectException(\App\Exceptions\Location\GeocodingFailedException::class);

        (new GeocodeUserAddress($user->id))->handle(app(UserAddressGeocoder::class));
    }

    public function test_the_retry_job_skips_addresses_that_are_no_longer_failed(): void
    {
        $this->configureGeocoding();
        Http::fake();
        $user = $this->professionalWithResolvedAddress();

        (new GeocodeUserAddress($user->id))->handle(app(UserAddressGeocoder::class));

        Http::assertNothingSent();
    }

    public function test_retry_command_enqueues_every_failed_address(): void
    {
        $this->configureGeocoding();
        Queue::fake();
        $this->professionalWithFailedAddress();
        $this->professionalWithFailedAddress();
        $this->professionalWithResolvedAddress();

        $this->artisan('geocoding:retry-failed')->assertSuccessful();

        Queue::assertPushed(GeocodeUserAddress::class, 2);
    }

    public function test_retry_command_fails_without_a_configured_provider(): void
    {
        config(['services.google.maps_api_key' => '']);
        Queue::fake();
        $this->professionalWithFailedAddress();

        $this->artisan('geocoding:retry-failed')->assertFailed();

        Queue::assertNothingPushed();
    }

    private function configureGeocoding(): void
    {
        config(['services.google.maps_api_key' => 'chave-de-teste']);
    }

    private function fakeGoogleAnswer(float $latitude, float $longitude): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => $latitude, 'lng' => $longitude]]]],
        ])]);
    }

    private function professionalWithResolvedAddress(): User
    {
        return User::factory()->professional()->create([
            'address' => 'Rua Antiga',
            'number' => '1',
            'neighborhood' => 'Centro',
            'city' => 'Poços de Caldas',
            'state' => 'MG',
            'zip_code' => '37701-000',
            'latitude' => -21.7878,
            'longitude' => -46.5613,
            'geocoding_status' => GeocodingStatus::RESOLVED,
        ]);
    }

    private function professionalWithFailedAddress(): User
    {
        return User::factory()->professional()->create([
            'address' => 'Rua Pendente',
            'number' => '2',
            'neighborhood' => 'Centro',
            'city' => 'Poços de Caldas',
            'state' => 'MG',
            'zip_code' => '37701-000',
            'latitude' => null,
            'longitude' => null,
            'geocoding_status' => GeocodingStatus::FAILED,
        ]);
    }
}
