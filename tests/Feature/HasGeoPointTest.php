<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cobre o trait `HasGeoPoint` (app/Models/Concerns/HasGeoPoint.php), que
 * substitui a escrita manual de `location` que existia espalhada em
 * `RegistrationCompletionController`. A coluna e `geography(POINT,4326)` —
 * Eloquent nao a le nativamente, entao a asserção usa SQL cru (ST_X/ST_Y).
 */
class HasGeoPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_latitude_and_longitude_syncs_the_location_column(): void
    {
        $user = User::factory()->tutor()->create([
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);

        $point = $this->fetchLocationPoint($user->id);

        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(-46.6333, $point->lng, 0.0001);
        $this->assertEqualsWithDelta(-23.5505, $point->lat, 0.0001);
    }

    public function test_updating_coordinates_resyncs_the_location_column(): void
    {
        $user = User::factory()->tutor()->create([
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);

        $user->update([
            'latitude' => -22.9099,
            'longitude' => -47.0626,
        ]);

        $point = $this->fetchLocationPoint($user->id);

        $this->assertEqualsWithDelta(-47.0626, $point->lng, 0.0001);
        $this->assertEqualsWithDelta(-22.9099, $point->lat, 0.0001);
    }

    public function test_user_without_coordinates_has_no_location(): void
    {
        $user = User::factory()->tutor()->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->assertNull($this->fetchLocationPoint($user->id));
    }

    public function test_saving_an_unrelated_field_does_not_rewrite_location(): void
    {
        $user = User::factory()->tutor()->create([
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);

        // Toca um campo que nao e geografico; o trait nao deve tentar
        // reescrever `location` (guard wasChanged() em HasGeoPoint::syncGeoPoint()).
        $user->update(['name' => 'Novo Nome']);

        $point = $this->fetchLocationPoint($user->id);

        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(-46.6333, $point->lng, 0.0001);
        $this->assertSame('Novo Nome', $user->fresh()->name);
    }

    private function fetchLocationPoint(int $userId): ?object
    {
        return DB::selectOne(
            'SELECT ST_X(location::geometry) AS lng, ST_Y(location::geometry) AS lat
             FROM users WHERE id = ? AND location IS NOT NULL',
            [$userId]
        );
    }
}
