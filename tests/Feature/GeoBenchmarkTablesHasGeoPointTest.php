<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\LostPetAlert;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 6 do plano de otimizacao — cobre a aplicacao do trait `HasGeoPoint`
 * (app/Models/Concerns/HasGeoPoint.php) em `Location` e `LostPetAlert`, os
 * dois models novos que passam a sincronizar uma coluna PostGIS. Segue o
 * mesmo padrao de `tests/Feature/HasGeoPointTest.php` (User).
 */
class GeoBenchmarkTablesHasGeoPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_location_syncs_the_location_column(): void
    {
        $professional = User::factory()->professional()->create();

        $location = Location::create([
            'professional_id' => $professional->id,
            'name' => 'Unidade Centro',
            'address' => 'Rua Augusta, 100',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);

        $point = $this->fetchGeoPoint('locations', 'location', $location->id);

        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(-46.6333, $point->lng, 0.0001);
        $this->assertEqualsWithDelta(-23.5505, $point->lat, 0.0001);
    }

    public function test_updating_a_location_coordinates_resyncs_the_location_column(): void
    {
        $professional = User::factory()->professional()->create();

        $location = Location::create([
            'professional_id' => $professional->id,
            'name' => 'Unidade Centro',
            'address' => 'Rua Augusta, 100',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);

        $location->update([
            'latitude' => -22.9099,
            'longitude' => -47.0626,
        ]);

        $point = $this->fetchGeoPoint('locations', 'location', $location->id);

        $this->assertEqualsWithDelta(-47.0626, $point->lng, 0.0001);
        $this->assertEqualsWithDelta(-22.9099, $point->lat, 0.0001);
    }

    public function test_saving_a_lost_pet_alert_syncs_the_last_seen_geo_column(): void
    {
        $owner = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $owner->id]);

        $alert = LostPetAlert::create([
            'pet_id' => $pet->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'description' => 'Visto pela última vez perto do parque.',
            'last_seen_location' => 'Parque Ibirapuera, São Paulo',
            'last_seen_latitude' => -23.5874,
            'last_seen_longitude' => -46.6576,
            'last_seen_at' => now(),
            'contact_info' => ['phone' => '11999999999'],
        ]);

        $point = $this->fetchGeoPoint('lost_pet_alerts', 'last_seen_geo', $alert->id);

        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(-46.6576, $point->lng, 0.0001);
        $this->assertEqualsWithDelta(-23.5874, $point->lat, 0.0001);
    }

    public function test_lost_pet_alert_without_coordinates_has_no_last_seen_geo(): void
    {
        $owner = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $owner->id]);

        $alert = LostPetAlert::create([
            'pet_id' => $pet->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'description' => 'Sem localizacao precisa.',
            'last_seen_location' => 'Bairro desconhecido',
            'last_seen_at' => now(),
            'contact_info' => ['phone' => '11999999999'],
        ]);

        $this->assertNull($this->fetchGeoPoint('lost_pet_alerts', 'last_seen_geo', $alert->id));
    }

    private function fetchGeoPoint(string $table, string $column, int $id): ?object
    {
        return DB::selectOne(
            "SELECT ST_X({$column}::geometry) AS lng, ST_Y({$column}::geometry) AS lat
             FROM {$table} WHERE id = ? AND {$column} IS NOT NULL",
            [$id]
        );
    }
}
