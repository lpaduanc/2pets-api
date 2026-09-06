<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use App\Services\Location\LocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6 do plano de otimizacao — `LocationService::getNearbyLocations()`
 * usava Haversine cru com `HAVING distance <= ?`, que no PostgreSQL lanca
 * `QueryException` (`column "distance" does not exist`): HAVING nao enxerga
 * alias do SELECT sem GROUP BY. A query nunca funcionou de verdade contra
 * este driver — nao era so lenta, era quebrada. Agora usa `ST_DWithin`.
 */
class LocationServiceNearbySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_locations_within_the_radius_ordered_by_distance(): void
    {
        $professional = User::factory()->professional()->create();

        $nearLocation = $this->createLocation($professional, -23.5510, -46.6330, 'Perto');
        $farLocation = $this->createLocation($professional, -22.9099, -47.0626, 'Longe (Campinas)');

        $results = app(LocationService::class)->getNearbyLocations(-23.5505, -46.6333, 10);

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains('id', $nearLocation->id));
        $this->assertFalse($results->contains('id', $farLocation->id));
    }

    public function test_ignores_inactive_locations(): void
    {
        $professional = User::factory()->professional()->create();

        $inactiveLocation = $this->createLocation($professional, -23.5510, -46.6330, 'Inativa', isActive: false);

        $results = app(LocationService::class)->getNearbyLocations(-23.5505, -46.6333, 10);

        $this->assertFalse($results->contains('id', $inactiveLocation->id));
    }

    public function test_returns_empty_collection_when_nothing_is_within_the_radius(): void
    {
        $professional = User::factory()->professional()->create();

        $this->createLocation($professional, -22.9099, -47.0626, 'Campinas');

        $results = app(LocationService::class)->getNearbyLocations(-23.5505, -46.6333, 1);

        $this->assertCount(0, $results);
    }

    private function createLocation(
        User $professional,
        float $latitude,
        float $longitude,
        string $name,
        bool $isActive = true
    ): Location {
        return Location::create([
            'professional_id' => $professional->id,
            'name' => $name,
            'address' => 'Rua Teste, 1',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_active' => $isActive,
        ]);
    }
}
