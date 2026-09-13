<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\PetVetAccessApproved;
use App\Notifications\PetVetAccessRejected;
use App\Notifications\PetVetAccessRequested;
use App\Notifications\PetVetAccessRevoked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
            'requested_access_level' => 'read',
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
        $this->postJson("/api/pet-vet-access/{$access->id}/accept", ['access_level' => 'read'])->assertOk();

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

    /**
     * Bug: o vet nunca era avisado de que o tutor aprovou o pedido — precisava ficar
     * tentando abrir a tela de pacientes para descobrir.
     */
    public function test_vet_is_notified_when_tutor_accepts(): void
    {
        Notification::fake();
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept", ['access_level' => 'write'])->assertOk();

        Notification::assertSentTo(
            $this->vet,
            PetVetAccessApproved::class,
            fn (PetVetAccessApproved $notification) => $notification->pet->id === $this->pet->id
                && $notification->grantedLevel === VetAccessLevel::WRITE
        );
    }

    /** Bug: o vet nunca era avisado de que o pedido foi recusado. */
    public function test_vet_is_notified_when_tutor_rejects(): void
    {
        Notification::fake();
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/reject", [
            'reason' => 'Não reconheço este profissional',
        ])->assertOk();

        Notification::assertSentTo(
            $this->vet,
            PetVetAccessRejected::class,
            fn (PetVetAccessRejected $notification) => $notification->reason === 'Não reconheço este profissional'
        );
    }

    public function test_non_owner_cannot_accept_request(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);
        $other = User::factory()->tutor()->create();

        Sanctum::actingAs($other);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept", ['access_level' => 'read'])->assertForbidden();
    }

    public function test_tutor_can_revoke_accepted_access(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);
        $access->accept(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/revoke", [
            'reason' => 'Trocando de veterinário',
        ])->assertOk();

        $access->refresh();
        $this->assertEquals(PetVetAccess::STATUS_REVOKED, $access->status);
        $this->assertFalse($access->is_active);
        $this->assertNotNull($access->revoked_at);
    }

    /**
     * Bug: o vet só descobria a revogação levando um 403 na frente do cliente — nunca era
     * avisado.
     */
    public function test_vet_is_notified_when_tutor_revokes(): void
    {
        Notification::fake();
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);
        $access->accept(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/revoke", [
            'reason' => 'Trocando de veterinário',
        ])->assertOk();

        Notification::assertSentTo(
            $this->vet,
            PetVetAccessRevoked::class,
            fn (PetVetAccessRevoked $notification) => $notification->reason === 'Trocando de veterinário'
        );
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
        ])->assertStatus(409)
            ->assertJsonPath('data.pet_id', $this->pet->id);
    }

    /**
     * Regressão: o app envia o CPF mascarado. A regra antiga (`size:11`) reprovava todo CPF
     * digitado por um humano e o fluxo inteiro devolvia 422 sem nunca chegar ao banco.
     */
    public function test_vet_request_accepts_masked_tutor_cpf(): void
    {
        Sanctum::actingAs($this->vet);

        $this->postJson('/api/pet-vet-access/request', [
            'tutor_cpf' => '123.456.789-01',
            'pet_data' => [
                'name' => 'Thor',
                'species' => 'dog',
                'gender' => 'male',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('pets', ['name' => 'Thor', 'user_id' => $this->tutor->id]);
    }

    /**
     * Regressão: a validação aceitava só `dog` e `cat`, enquanto o formulário do app oferece
     * as sete espécies do enum PetSpecies.
     */
    public function test_vet_request_accepts_every_species_of_the_enum(): void
    {
        Sanctum::actingAs($this->vet);

        $this->postJson('/api/pet-vet-access/request', [
            'tutor_cpf' => $this->tutor->cpf,
            'pet_data' => [
                'name' => 'Piu',
                'species' => 'bird',
                'gender' => 'male',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('pets', ['name' => 'Piu', 'species' => 'bird']);
    }

    public function test_unknown_species_is_rejected(): void
    {
        Sanctum::actingAs($this->vet);

        $this->postJson('/api/pet-vet-access/request', [
            'tutor_cpf' => $this->tutor->cpf,
            'pet_data' => [
                'name' => 'Nemo',
                'species' => 'dragon',
                'gender' => 'male',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('pet_data.species');
    }

    public function test_request_for_unknown_cpf_returns_not_found_and_creates_no_pet(): void
    {
        Sanctum::actingAs($this->vet);

        $this->postJson('/api/pet-vet-access/request', [
            'tutor_cpf' => '99988877766',
            'pet_data' => [
                'name' => 'Fantasma',
                'species' => 'dog',
                'gender' => 'male',
            ],
        ])->assertNotFound();

        $this->assertDatabaseMissing('pets', ['name' => 'Fantasma']);
    }

    public function test_tutor_cannot_request_access(): void
    {
        Sanctum::actingAs($this->tutor);

        $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
        ])->assertForbidden();
    }

    public function test_tutor_sees_the_pending_request_of_the_vet(): void
    {
        Sanctum::actingAs($this->vet);
        $this->postJson('/api/pet-vet-access/request', ['pet_id' => $this->pet->id])->assertCreated();

        Sanctum::actingAs($this->tutor);
        $this->getJson('/api/pet-vet-access/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pet_id', $this->pet->id)
            ->assertJsonPath('data.0.veterinarian_id', $this->vet->id);
    }

    public function test_notification_is_sent_to_the_tutor(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->vet);
        $this->postJson('/api/pet-vet-access/request', ['pet_id' => $this->pet->id])->assertCreated();

        Notification::assertSentTo($this->tutor, PetVetAccessRequested::class);
    }
}
