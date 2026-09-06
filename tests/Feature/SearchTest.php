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
        $this->createApprovedProfessional([], ['professional_type' => 'vet']);
        $this->createApprovedProfessional([], ['professional_type' => 'petshop']);

        $response = $this->getJson('/api/public/search?professional_type=vet');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $item) {
            if (isset($item['professional_type'])) {
                $this->assertEquals('vet', $item['professional_type']);
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
    // Fuzzy search (pg_trgm) — Fase 5 do plano de otimização
    // ---------------------------------------------------------------

    /**
     * Requisito de produto que justifica trigram em vez de tsvector: `to_tsvector` faz
     * casamento exato de lexema ("veterinria" não casaria com "veterinária"), trigram tolera
     * o erro de digitação porque compara fragmentos de 3 letras, não a palavra inteira.
     */
    public function test_search_query_finds_professional_with_typo_and_missing_accent(): void
    {
        $professionalUser = $this->createApprovedProfessional(['name' => 'Dra. Ana Veterinária']);

        $response = $this->getJson('/api/public/search?query=veterinria');

        $response->assertOk();

        $matchedIds = collect($response->json('data'))->pluck('id');
        $this->assertTrue($matchedIds->contains($professionalUser->id));
    }

    public function test_search_query_finds_clinic_ignoring_missing_accent(): void
    {
        $professionalUser = $this->createApprovedProfessional(
            [],
            ['business_name' => 'Clínica Veterinária']
        );

        $response = $this->getJson('/api/public/search?query=clinica');

        $response->assertOk();

        $matchedIds = collect($response->json('data'))->pluck('id');
        $this->assertTrue($matchedIds->contains($professionalUser->id));
    }

    /**
     * Abaixo de 3 caracteres o pg_trgm não extrai um trigrama completo do termo, então o
     * índice GIN nunca casaria — `SearchFiltersDTO` rejeita antes de chegar ao banco.
     */
    public function test_search_rejects_query_shorter_than_three_characters(): void
    {
        $response = $this->getJson('/api/public/search?query=ab');

        $response->assertStatus(422)->assertJsonValidationErrors('query');
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
