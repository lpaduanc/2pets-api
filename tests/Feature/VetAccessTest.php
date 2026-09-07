<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\PetVetAccessRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

        $this->tutor = User::factory()->tutor()->create(['cpf' => '12345678909']);
        $this->vet = User::factory()->veterinarian()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
    }

    // ===============================================================
    // Grant / list / revoke
    // ===============================================================

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
            'status' => PetVetAccess::STATUS_ACCEPTED,
        ]);
    }

    public function test_tutor_can_revoke_vet_access(): void
    {
        Sanctum::actingAs($this->tutor);

        $access = $this->createAcceptedAccess();

        $response = $this->postJson("/api/pet-vet-access/{$access->id}/revoke");

        $response->assertOk()
            ->assertJson(['message' => 'Acesso revogado com sucesso.']);

        $this->assertDatabaseHas('pet_vet_accesses', [
            'id' => $access->id,
            'is_active' => false,
        ]);
    }

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

    public function test_vet_can_list_accessed_pets(): void
    {
        $this->createAcceptedAccess();

        Sanctum::actingAs($this->vet);

        $response = $this->getJson('/api/pet-vet-access/my-accesses');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['access_id', 'access_level', 'is_active', 'pet', 'tutor'],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->pet->id, $data[0]['pet']['id']);
        $this->assertEquals($this->tutor->name, $data[0]['tutor']['name']);
    }

    public function test_my_patients_alias_returns_same_payload_as_my_accesses(): void
    {
        $this->createAcceptedAccess();

        Sanctum::actingAs($this->vet);

        $aliasResponse = $this->getJson('/api/professional/my-patients');
        $canonicalResponse = $this->getJson('/api/pet-vet-access/my-accesses');

        $aliasResponse->assertOk();
        $canonicalResponse->assertOk();

        $this->assertEquals(
            $canonicalResponse->json('data.0.access_id'),
            $aliasResponse->json('data.0.access_id')
        );
    }

    public function test_tutor_can_list_pet_accesses(): void
    {
        $secondVet = User::factory()->veterinarian()->create();

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => 'read',
            'granted_at' => now(),
            'is_active' => true,
            'status' => PetVetAccess::STATUS_ACCEPTED,
        ]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $secondVet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => 'write',
            'granted_at' => now(),
            'is_active' => true,
            'status' => PetVetAccess::STATUS_ACCEPTED,
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

    // ===============================================================
    // Pet read: vet with active access can see the pet
    // ===============================================================

    public function test_vet_with_active_access_can_view_pet(): void
    {
        $this->createAcceptedAccess();

        Sanctum::actingAs($this->vet);

        $response = $this->getJson("/api/pets/{$this->pet->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $this->pet->id)
            ->assertJsonPath('data.name', $this->pet->name);

        // Vets must NOT see the list of other vets who have access to the pet.
        $this->assertArrayNotHasKey('vet_accesses', $response->json('data'));
    }

    public function test_stranger_vet_cannot_view_pet(): void
    {
        // No access row created — this vet is a random stranger.
        Sanctum::actingAs($this->vet);

        $response = $this->getJson("/api/pets/{$this->pet->id}");

        $response->assertStatus(403);
    }

    public function test_revoked_access_blocks_pet_read_immediately(): void
    {
        $access = $this->createAcceptedAccess();
        $access->revoke($this->tutor->id, 'test revocation');

        Sanctum::actingAs($this->vet);

        $response = $this->getJson("/api/pets/{$this->pet->id}");

        $response->assertStatus(403);
    }

    public function test_vet_patients_scope_returns_granted_pets_only(): void
    {
        $this->createAcceptedAccess();

        // Another pet the vet has no grant for.
        $otherTutor = User::factory()->tutor()->create();
        Pet::factory()->create(['user_id' => $otherTutor->id]);

        Sanctum::actingAs($this->vet);

        $response = $this->getJson('/api/pets?scope=patients');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($this->pet->id, $data[0]['id']);
    }

    // ===============================================================
    // Health records nested under pet
    // ===============================================================

    public function test_vet_with_access_can_list_vaccinations(): void
    {
        $this->createAcceptedAccess();

        Sanctum::actingAs($this->vet);

        $response = $this->getJson("/api/pets/{$this->pet->id}/health/vaccinations");

        $response->assertOk()->assertJsonStructure(['data']);
    }

    public function test_vet_with_read_only_cannot_create_medication(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/medications", [
            'name' => 'Test Med',
            'dosage' => '5mg',
            'frequency' => '1x dia',
            'start_date' => now()->toDateString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_vet_with_write_can_create_medication(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::WRITE);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/medications", [
            'name' => 'Test Med',
            'dosage' => '5mg',
            'frequency' => '1x dia',
            'start_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Test Med');
    }

    public function test_vet_with_full_can_create_medication(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::FULL);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/medications", [
            'name' => 'Full Access Med',
            'dosage' => '10mg',
            'frequency' => '2x dia',
            'start_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
    }

    public function test_owner_can_create_vaccination_without_any_professional_involved(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.professional_id', null);

        $this->assertDatabaseHas('vaccinations', [
            'pet_id' => $this->pet->id,
            'vaccine_name' => 'V10',
            'professional_id' => null,
        ]);
    }

    public function test_owner_can_create_surgery_without_any_professional_involved(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/surgeries", [
            'surgery_type' => 'Castração',
            'surgery_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.professional_id', null);

        $this->assertDatabaseHas('surgeries', [
            'pet_id' => $this->pet->id,
            'surgery_type' => 'Castração',
            'professional_id' => null,
        ]);
    }

    public function test_vet_with_write_creating_vaccination_records_own_professional_id(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::WRITE);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'Antirrábica',
            'application_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.professional_id', $this->vet->id);

        $this->assertDatabaseHas('vaccinations', [
            'pet_id' => $this->pet->id,
            'vaccine_name' => 'Antirrábica',
            'professional_id' => $this->vet->id,
        ]);
    }

    public function test_vet_with_write_creating_surgery_records_own_professional_id(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::WRITE);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/surgeries", [
            'surgery_type' => 'Ortopedia',
            'surgery_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.professional_id', $this->vet->id);

        $this->assertDatabaseHas('surgeries', [
            'pet_id' => $this->pet->id,
            'surgery_type' => 'Ortopedia',
            'professional_id' => $this->vet->id,
        ]);
    }

    // ===============================================================
    // Weight history
    // ===============================================================

    public function test_owner_can_add_weight_entry(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson("/api/pets/{$this->pet->id}/weights", [
            'weight_kg' => 12.5,
            'measured_at' => now()->toDateTimeString(),
            'note' => 'Pesagem de rotina',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.weight', '12.50');
        $this->assertDatabaseHas('pet_weight_history', [
            'pet_id' => $this->pet->id,
            'measured_by_user_id' => $this->tutor->id,
        ]);
    }

    public function test_vet_with_write_can_add_weight_entry(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::WRITE);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/pets/{$this->pet->id}/weights", [
            'weight_kg' => 13.0,
            'measured_at' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pet_weight_history', [
            'pet_id' => $this->pet->id,
            'measured_by_user_id' => $this->vet->id,
        ]);
    }

    public function test_vet_with_read_only_cannot_add_weight_entry(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/pets/{$this->pet->id}/weights", [
            'weight_kg' => 13.0,
            'measured_at' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_stranger_cannot_add_weight_entry(): void
    {
        $stranger = User::factory()->veterinarian()->create();
        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/pets/{$this->pet->id}/weights", [
            'weight_kg' => 15.0,
            'measured_at' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_vet_with_read_can_list_weight_history(): void
    {
        $this->createAcceptedAccess(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);

        $response = $this->getJson("/api/pets/{$this->pet->id}/weights");

        $response->assertOk();
    }

    // ===============================================================
    // Notification on access request
    // ===============================================================

    public function test_requesting_access_dispatches_notification_to_tutor(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->vet);

        $response = $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'read',
        ]);

        $response->assertStatus(201);

        Notification::assertSentTo(
            $this->tutor,
            PetVetAccessRequested::class,
            function (PetVetAccessRequested $notification) {
                return $notification->pet->id === $this->pet->id
                    && $notification->vet->id === $this->vet->id;
            }
        );
    }

    // ===============================================================
    // Helpers
    // ===============================================================

    private function createAcceptedAccess(VetAccessLevel $level = VetAccessLevel::READ): PetVetAccess
    {
        return PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => $level->value,
            'granted_at' => now(),
            'is_active' => true,
            'status' => PetVetAccess::STATUS_ACCEPTED,
        ]);
    }
}
