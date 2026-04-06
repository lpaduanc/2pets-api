<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VetAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private User $vet;
    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->vet = User::factory()->veterinarian()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
    }

    // ---------------------------------------------------------------
    // Grant access
    // ---------------------------------------------------------------

    public function test_tutor_can_grant_vet_access(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/pet-vet-access/grant', [
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'access_level' => 'read',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'pet_id', 'veterinarian_id', 'access_level', 'is_active'],
            ]);

        $this->assertDatabaseHas('pet_vet_accesses', [
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // Revoke access
    // ---------------------------------------------------------------

    public function test_tutor_can_revoke_vet_access(): void
    {
        Sanctum::actingAs($this->tutor);

        $access = PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => 'read',
            'granted_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/pet-vet-access/{$access->id}/revoke");

        $response->assertOk()
            ->assertJson(['message' => 'Acesso revogado com sucesso.']);

        $this->assertDatabaseHas('pet_vet_accesses', [
            'id' => $access->id,
            'is_active' => false,
        ]);
    }

    // ---------------------------------------------------------------
    // Authorization: only pet owner can grant
    // ---------------------------------------------------------------

    public function test_non_owner_cannot_grant_access(): void
    {
        $otherTutor = User::factory()->tutor()->create();
        Sanctum::actingAs($otherTutor);

        $response = $this->postJson('/api/pet-vet-access/grant', [
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'access_level' => 'read',
        ]);

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Vet lists accessed pets
    // ---------------------------------------------------------------

    public function test_vet_can_list_accessed_pets(): void
    {
        // Grant access to the vet
        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => 'read',
            'granted_at' => now(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->vet);

        $response = $this->getJson('/api/pet-vet-access/my-accesses');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'pet_id', 'veterinarian_id', 'access_level', 'is_active'],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->pet->id, $data[0]['pet_id']);
    }

    // ---------------------------------------------------------------
    // Tutor lists pet accesses
    // ---------------------------------------------------------------

    public function test_tutor_can_list_pet_accesses(): void
    {
        // Create two vet accesses for the same pet
        $secondVet = User::factory()->veterinarian()->create();

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => 'read',
            'granted_at' => now(),
            'is_active' => true,
        ]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $secondVet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => 'write',
            'granted_at' => now(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson("/api/pet-vet-access/pet/{$this->pet->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'pet_id', 'veterinarian_id', 'access_level', 'is_active'],
                ],
            ]);

        $this->assertCount(2, $response->json('data'));
    }
}
