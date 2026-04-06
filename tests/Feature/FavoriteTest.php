<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private User $professionalUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();

        $this->professionalUser = User::factory()->professional()->create([
            'profile_completed' => true,
            'registration_status' => 'approved',
        ]);

        Professional::factory()->create([
            'user_id' => $this->professionalUser->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Toggle favorite (add)
    // ---------------------------------------------------------------

    public function test_user_can_favorite_professional(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson("/api/favorites/{$this->professionalUser->id}");

        $response->assertStatus(201)
            ->assertJson([
                'favorited' => true,
            ]);

        $this->assertDatabaseHas('favorites', [
            'user_id' => $this->tutor->id,
            'professional_id' => $this->professionalUser->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Toggle favorite (remove)
    // ---------------------------------------------------------------

    public function test_user_can_unfavorite_professional(): void
    {
        Sanctum::actingAs($this->tutor);

        // First add the favorite
        Favorite::create([
            'user_id' => $this->tutor->id,
            'professional_id' => $this->professionalUser->id,
        ]);

        // Toggle again to remove
        $response = $this->postJson("/api/favorites/{$this->professionalUser->id}");

        $response->assertOk()
            ->assertJson([
                'favorited' => false,
            ]);

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $this->tutor->id,
            'professional_id' => $this->professionalUser->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Check status
    // ---------------------------------------------------------------

    public function test_user_can_check_favorite_status(): void
    {
        Sanctum::actingAs($this->tutor);

        // Not favorited
        $response = $this->getJson("/api/favorites/check/{$this->professionalUser->id}");
        $response->assertOk()
            ->assertJson(['favorited' => false]);

        // Add favorite
        Favorite::create([
            'user_id' => $this->tutor->id,
            'professional_id' => $this->professionalUser->id,
        ]);

        // Now favorited
        $response = $this->getJson("/api/favorites/check/{$this->professionalUser->id}");
        $response->assertOk()
            ->assertJson(['favorited' => true]);
    }

    // ---------------------------------------------------------------
    // List favorites
    // ---------------------------------------------------------------

    public function test_user_can_list_favorites(): void
    {
        Sanctum::actingAs($this->tutor);

        // Create a second professional
        $secondPro = User::factory()->professional()->create([
            'profile_completed' => true,
            'registration_status' => 'approved',
        ]);
        Professional::factory()->create(['user_id' => $secondPro->id]);

        // Favorite both
        Favorite::create([
            'user_id' => $this->tutor->id,
            'professional_id' => $this->professionalUser->id,
        ]);
        Favorite::create([
            'user_id' => $this->tutor->id,
            'professional_id' => $secondPro->id,
        ]);

        $response = $this->getJson('/api/favorites');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'total'],
            ]);

        $this->assertEquals(2, $response->json('meta.total'));
    }
}
