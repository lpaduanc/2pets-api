<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/api/me/search-location` — última localização que o usuário logado confirmou na busca,
 * usada como padrão na próxima visita.
 */
class SearchLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_null_before_any_location_is_confirmed(): void
    {
        Sanctum::actingAs(User::factory()->tutor()->create());

        $this->getJson('/api/me/search-location')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_it_remembers_the_confirmed_location_with_rounded_coordinates(): void
    {
        Sanctum::actingAs(User::factory()->tutor()->create());

        $this->putJson('/api/me/search-location', [
            'latitude' => -21.787812,
            'longitude' => -46.561345,
            'source' => 'zip_code',
            'label' => 'Centro, Poços de Caldas',
            'zip_code' => '37701-000',
            'neighborhood' => 'Centro',
            'city' => 'Poços de Caldas',
            'state' => 'mg',
        ])->assertOk();

        $this->getJson('/api/me/search-location')
            ->assertOk()
            ->assertJson(['data' => [
                'source' => 'zip_code',
                'label' => 'Centro, Poços de Caldas',
                'zip_code' => '37701000',
                'state' => 'MG',
                'latitude' => -21.788,
                'longitude' => -46.561,
            ]]);
    }

    public function test_a_new_confirmation_replaces_the_previous_one(): void
    {
        $user = User::factory()->tutor()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/search-location', ['latitude' => -21.78, 'longitude' => -46.56, 'source' => 'gps'])->assertOk();
        $this->putJson('/api/me/search-location', ['latitude' => -23.55, 'longitude' => -46.63, 'source' => 'gps'])->assertOk();

        $this->assertDatabaseCount('user_search_locations', 1);
        $this->getJson('/api/me/search-location')->assertJsonPath('data.latitude', -23.55);
    }

    public function test_each_user_only_sees_their_own_location(): void
    {
        Sanctum::actingAs(User::factory()->tutor()->create());
        $this->putJson('/api/me/search-location', ['latitude' => -21.78, 'longitude' => -46.56, 'source' => 'gps'])->assertOk();

        Sanctum::actingAs(User::factory()->tutor()->create());

        $this->getJson('/api/me/search-location')->assertExactJson(['data' => null]);
    }

    public function test_it_validates_the_payload(): void
    {
        Sanctum::actingAs(User::factory()->tutor()->create());

        $this->putJson('/api/me/search-location', ['latitude' => 120, 'source' => 'satellite', 'zip_code' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude', 'source', 'zip_code']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/me/search-location')->assertUnauthorized();
        $this->putJson('/api/me/search-location', [])->assertUnauthorized();
    }
}
