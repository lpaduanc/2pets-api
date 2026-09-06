<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cobre o workflow consentimento-primeiro (CLAUDE.md §3):
 *   vet solicita → tutor aceita/rejeita → tutor pode revogar depois.
 */
class VetAccessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $vet;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create(['cpf' => '12345678901']);
        $this->vet = User::factory()->veterinarian()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
    }

    public function test_vet_request_by_pet_id_creates_pending_access(): void
    {
        Sanctum::actingAs($this->vet);

        $response = $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'access_level' => 'read',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('pet_vet_accesses', [
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'status' => PetVetAccess::STATUS_PENDING,
            'is_active' => false,
        ]);
    }

    public function test_vet_request_by_cpf_creates_pet_and_pending_access(): void
    {
        Sanctum::actingAs($this->vet);

        $response = $this->postJson('/api/pet-vet-access/request', [
            'tutor_cpf' => $this->tutor->cpf,
            'pet_data' => [
                'name' => 'Thor',
                'species' => 'dog',
                'gender' => 'male',
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('pets', ['name' => 'Thor', 'user_id' => $this->tutor->id]);
        $this->assertDatabaseHas('pet_vet_accesses', [
            'veterinarian_id' => $this->vet->id,
            'status' => PetVetAccess::STATUS_PENDING,
        ]);
    }

    public function test_pending_access_does_not_count_as_active(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        $this->assertFalse(
            PetVetAccess::active()->where('id', $access->id)->exists(),
            'Acesso pendente não pode ser considerado ativo.'
        );
    }

    public function test_tutor_accepts_and_access_becomes_active(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept")->assertOk();

        $access->refresh();
        $this->assertEquals(PetVetAccess::STATUS_ACCEPTED, $access->status);
        $this->assertTrue($access->is_active);
        $this->assertNotNull($access->granted_at);
    }

    public function test_tutor_rejects_with_reason(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/reject", [
            'reason' => 'Não reconheço este profissional',
        ])->assertOk();

        $access->refresh();
        $this->assertEquals(PetVetAccess::STATUS_REJECTED, $access->status);
        $this->assertFalse($access->is_active);
        $this->assertEquals('Não reconheço este profissional', $access->rejection_reason);
    }

    public function test_non_owner_cannot_accept_request(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);
        $other = User::factory()->tutor()->create();

        Sanctum::actingAs($other);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept")->assertForbidden();
    }

    public function test_tutor_can_revoke_accepted_access(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);
        $access->accept();

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/revoke", [
            'reason' => 'Trocando de veterinário',
        ])->assertOk();

        $access->refresh();
        $this->assertEquals(PetVetAccess::STATUS_REVOKED, $access->status);
        $this->assertFalse($access->is_active);
        $this->assertNotNull($access->revoked_at);
    }

    public function test_tutor_cannot_revoke_non_accepted_access(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/revoke")->assertStatus(422);
    }

    public function test_duplicate_request_is_blocked(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->vet);
        $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
        ])->assertStatus(409);
    }
}
