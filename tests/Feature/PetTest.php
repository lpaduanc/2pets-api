<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PetTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    public function test_tutor_can_create_pet(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/pets', [
            'name' => 'Rex',
            'species' => 'dog',
            'gender' => 'male',
            'breed' => 'Labrador',
            'birth_date' => '2022-01-15',
            'weight' => 25.5,
            'color' => 'Golden',
            'neutered' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'species', 'gender', 'breed'],
            ]);

        $this->assertDatabaseHas('pets', [
            'name' => 'Rex',
            'species' => 'dog',
            'user_id' => $this->tutor->id,
        ]);
    }

    // ---------------------------------------------------------------
    // List
    // ---------------------------------------------------------------

    public function test_tutor_can_list_own_pets(): void
    {
        Sanctum::actingAs($this->tutor);

        Pet::factory()->count(3)->create(['user_id' => $this->tutor->id]);

        $response = $this->getJson('/api/pets');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_tutor_cannot_see_other_users_pets(): void
    {
        Sanctum::actingAs($this->tutor);

        $otherUser = User::factory()->tutor()->create();
        Pet::factory()->count(2)->create(['user_id' => $otherUser->id]);
        Pet::factory()->create(['user_id' => $this->tutor->id]);

        $response = $this->getJson('/api/pets');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ---------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------

    public function test_tutor_can_update_own_pet(): void
    {
        Sanctum::actingAs($this->tutor);

        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Rex']);

        $response = $this->putJson("/api/pets/{$pet->id}", [
            'name' => 'Rex Jr.',
            'species' => 'dog',
            'gender' => 'male',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('pets', [
            'id' => $pet->id,
            'name' => 'Rex Jr.',
        ]);
    }

    // ---------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------

    public function test_tutor_can_delete_own_pet(): void
    {
        Sanctum::actingAs($this->tutor);

        $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $response = $this->deleteJson("/api/pets/{$pet->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Pet removido com sucesso!']);

        // Pet usa SoftDeletes (CLAUDE.md: "soft delete em tudo"). A linha continua no banco
        // com `deleted_at` preenchido — assertDatabaseMissing aqui exigiria hard delete e
        // contrariaria a regra de auditoria do projeto.
        $this->assertSoftDeleted('pets', ['id' => $pet->id]);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_pet_creation_validates_required_fields(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/pets', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'species', 'gender']);
    }

    public function test_pet_species_must_be_valid(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/pets', [
            'name' => 'Nemo',
            'species' => 'dinosaur',
            'gender' => 'male',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['species']);
    }

    // ---------------------------------------------------------------
    // Auth guard
    // ---------------------------------------------------------------

    public function test_unauthenticated_cannot_access_pets(): void
    {
        $response = $this->getJson('/api/pets');

        $response->assertStatus(401);
    }
}
