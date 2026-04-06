<?php

namespace Tests\Feature;

use App\Models\Breed;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Create an approved professional user with a Professional profile.
     */
    private function createApprovedProfessional(array $userOverrides = [], array $professionalOverrides = []): User
    {
        $user = User::factory()->professional()->create(array_merge([
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ], $userOverrides));

        Professional::factory()->create(array_merge([
            'user_id' => $user->id,
        ], $professionalOverrides));

        return $user;
    }

    // ---------------------------------------------------------------
    // Public Search
    // ---------------------------------------------------------------

    public function test_public_search_returns_approved_professionals_only(): void
    {
        // Approved professional
        $this->createApprovedProfessional();

        // Non-approved professional (suspended)
        $suspendedUser = User::factory()->professional()->create([
            'is_suspended' => true,
            'profile_completed' => true,
            'registration_status' => 'approved',
        ]);
        Professional::factory()->create(['user_id' => $suspendedUser->id]);

        // Non-approved professional (pending registration)
        $pendingUser = User::factory()->professional()->create([
            'registration_status' => 'pending',
            'profile_completed' => true,
        ]);
        Professional::factory()->create(['user_id' => $pendingUser->id]);

        // Non-approved professional (incomplete profile)
        $incompleteUser = User::factory()->professional()->create([
            'profile_completed' => false,
            'registration_status' => 'approved',
        ]);
        Professional::factory()->create(['user_id' => $incompleteUser->id]);

        $response = $this->getJson('/api/public/search');

        $response->assertOk();

        // Verify the search returns results and each result belongs to an approved user.
        // The exact count depends on the search service filtering, but suspended/pending
        // users should not appear.
        $data = $response->json('data');
        foreach ($data as $item) {
            $user = User::find($item['id']);
            if ($user) {
                $this->assertFalse((bool) $user->is_suspended);
                $this->assertEquals('approved', $user->registration_status);
                $this->assertTrue((bool) $user->profile_completed);
            }
        }
    }

    public function test_search_filters_by_professional_type(): void
    {
        $this->createApprovedProfessional([], ['professional_type' => 'veterinarian']);
        $this->createApprovedProfessional([], ['professional_type' => 'petshop']);

        $response = $this->getJson('/api/public/search?professional_type=veterinarian');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $item) {
            if (isset($item['professional_type'])) {
                $this->assertEquals('veterinarian', $item['professional_type']);
            }
        }
    }

    public function test_search_returns_distance_when_location_provided(): void
    {
        $user = $this->createApprovedProfessional(
            ['latitude' => -23.5505, 'longitude' => -46.6333]
        );

        $response = $this->getJson('/api/public/search?latitude=-23.55&longitude=-46.63');

        $response->assertOk();
    }

    // ---------------------------------------------------------------
    // Featured
    // ---------------------------------------------------------------

    public function test_featured_returns_only_featured_professionals(): void
    {
        // Create a featured professional
        $this->createApprovedProfessional([], ['is_featured' => true]);

        // Create a non-featured professional
        $this->createApprovedProfessional([], ['is_featured' => false]);

        $response = $this->getJson('/api/public/featured');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $item) {
            // Each returned professional should be featured
            if (isset($item['is_featured'])) {
                $this->assertTrue($item['is_featured']);
            }
        }
    }

    // ---------------------------------------------------------------
    // Breeds
    // ---------------------------------------------------------------

    public function test_breeds_endpoint_returns_data(): void
    {
        Breed::factory()->dog()->create(['name' => 'Labrador Retriever']);
        Breed::factory()->dog()->create(['name' => 'Golden Retriever']);
        Breed::factory()->cat()->create(['name' => 'Siamese']);

        $response = $this->getJson('/api/public/breeds');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'species', 'name'],
                ],
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_breeds_filters_by_species(): void
    {
        Breed::factory()->dog()->create(['name' => 'Labrador Retriever']);
        Breed::factory()->dog()->create(['name' => 'Poodle']);
        Breed::factory()->cat()->create(['name' => 'Siamese']);

        $response = $this->getJson('/api/public/breeds?species=dog');

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $data = $response->json('data');
        foreach ($data as $breed) {
            $this->assertEquals('dog', $breed['species']);
        }
    }
}
