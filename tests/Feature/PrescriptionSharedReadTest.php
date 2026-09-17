<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/pets/{pet}/prescriptions` — leitura compartilhada tutor + veterinário, contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §1/§6.
 *
 * Triângulo de autorização (mesmo espírito de `MedicalRecordPolicy`): tutor dono do pet, o
 * profissional autor, ou vet com `PetVetAccess` ativo. Prescrição NÃO EMITIDA nunca aparece
 * aqui, nem para o tutor — mesma regra do rascunho de prontuário.
 */
class PrescriptionSharedReadTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private Pet $pet;

    private User $activeVet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
        $this->activeVet = User::factory()->professional()->create();

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->activeVet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
    }

    public function test_tutor_sees_issued_prescriptions_including_canceled_ones(): void
    {
        $issued = $this->createPrescription($this->activeVet, ['issued_at' => now()->subDay()]);
        $canceled = $this->createPrescription($this->activeVet, [
            'issued_at' => now()->subDays(2),
            'canceled_at' => now(),
            'canceled_reason' => 'Erro de dose',
            'canceled_by' => $this->activeVet->id,
        ]);
        $this->createPrescription($this->activeVet); // não emitida — nunca aparece

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson("/api/pets/{$this->pet->id}/prescriptions");

        $response->assertOk()->assertJsonCount(2, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($issued->id, $ids);
        $this->assertContains($canceled->id, $ids);
    }

    public function test_unissued_prescription_never_appears_even_to_the_tutor(): void
    {
        $this->createPrescription($this->activeVet);

        Sanctum::actingAs($this->tutor);

        $this->getJson("/api/pets/{$this->pet->id}/prescriptions")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_vet_with_active_access_sees_the_whole_pet_history(): void
    {
        $anotherVet = User::factory()->professional()->create();
        $this->createPrescription($this->activeVet, ['issued_at' => now()]);
        $this->createPrescription($anotherVet, ['issued_at' => now()]);

        Sanctum::actingAs($this->activeVet);

        $this->getJson("/api/pets/{$this->pet->id}/prescriptions")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * O vet perde `PetVetAccess` ativo depois de ter emitido a receita, mas continua vendo o
     * que ELE MESMO prescreveu — só não vê o que outro vet prescreveu para o mesmo pet.
     */
    public function test_a_vet_without_active_access_sees_only_their_own_authored_prescriptions(): void
    {
        $revokedVet = User::factory()->professional()->create();
        $ownPrescription = $this->createPrescription($revokedVet, ['issued_at' => now()]);
        $this->createPrescription($this->activeVet, ['issued_at' => now()]);

        Sanctum::actingAs($revokedVet);

        $response = $this->getJson("/api/pets/{$this->pet->id}/prescriptions");

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownPrescription->id);
    }

    public function test_a_stranger_with_no_relation_to_the_pet_is_forbidden(): void
    {
        $this->createPrescription($this->activeVet, ['issued_at' => now()]);

        Sanctum::actingAs(User::factory()->professional()->create());

        $this->getJson("/api/pets/{$this->pet->id}/prescriptions")->assertStatus(403);
    }

    public function test_orders_by_issuance_date_descending(): void
    {
        $older = $this->createPrescription($this->activeVet, ['issued_at' => now()->subDays(5)]);
        $newer = $this->createPrescription($this->activeVet, ['issued_at' => now()->subDay()]);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson("/api/pets/{$this->pet->id}/prescriptions");

        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPrescription(User $professional, array $overrides = []): Prescription
    {
        $prescription = Prescription::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $professional->id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'remote_orientation',
        ]);

        if ($overrides !== []) {
            $prescription->forceFill($overrides)->save();
        }

        $prescription->items()->create([
            'position' => 1,
            'commercial_name' => 'Amoxicilina',
            'dose_value' => 250,
            'dose_unit' => 'mg',
        ]);

        return $prescription;
    }
}
