<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /api/prescriptions/{id}/items/{itemId}/promote-to-medication` — doc de domínio
 * docs/atendimento-veterinario/02-receituario-dominio.md §5.2. SEMPRE ação explícita do
 * tutor: nunca automática, nunca do profissional.
 */
class PrescriptionMedicationPromotionTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
    }

    public function test_tutor_can_promote_an_issued_continuous_use_item(): void
    {
        $item = $this->createIssuedItem(['is_continuous_use' => true]);

        Sanctum::actingAs($this->tutor);

        $this->postJson($this->promoteUrl($item))
            ->assertCreated()
            ->assertJsonPath('data.pet_id', $this->pet->id)
            ->assertJsonPath('data.prescribed_by', $this->professional->id);

        $this->assertDatabaseHas('pet_medications', [
            'pet_id' => $this->pet->id,
            'prescribed_by' => $this->professional->id,
            'active' => true,
        ]);
    }

    public function test_the_professional_who_wrote_it_cannot_promote_it(): void
    {
        $item = $this->createIssuedItem(['is_continuous_use' => true]);

        Sanctum::actingAs($this->professional);

        $this->postJson($this->promoteUrl($item))->assertStatus(403);
    }

    public function test_a_stranger_cannot_promote_someone_elses_item(): void
    {
        $item = $this->createIssuedItem(['is_continuous_use' => true]);

        Sanctum::actingAs(User::factory()->tutor()->create());

        $this->postJson($this->promoteUrl($item))->assertStatus(403);
    }

    public function test_an_item_not_marked_as_continuous_use_cannot_be_promoted(): void
    {
        $item = $this->createIssuedItem(['is_continuous_use' => false]);

        Sanctum::actingAs($this->tutor);

        $this->postJson($this->promoteUrl($item))->assertStatus(422);
        $this->assertDatabaseCount('pet_medications', 0);
    }

    public function test_a_not_yet_issued_item_cannot_be_promoted(): void
    {
        $prescription = Prescription::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'remote_orientation',
        ]);
        $item = $prescription->items()->create([
            'position' => 1,
            'commercial_name' => 'Levotiroxina',
            'is_continuous_use' => true,
        ]);

        Sanctum::actingAs($this->tutor);

        $this->postJson($this->promoteUrl($item))->assertStatus(404);
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     */
    private function createIssuedItem(array $itemOverrides): PrescriptionItem
    {
        $prescription = Prescription::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'continuous_medication_renewal',
        ]);
        // `issued_at` fica fora de `$fillable` de propósito (só `PrescriptionLifecycleService`
        // grava essa coluna) — o teste simula o estado "já emitida" diretamente.
        $prescription->forceFill(['issued_at' => now()])->save();

        return $prescription->items()->create(array_merge([
            'position' => 1,
            'commercial_name' => 'Levotiroxina',
            'dose_value' => 0.1,
            'dose_unit' => 'mg',
            'frequency' => 'sid',
        ], $itemOverrides));
    }

    private function promoteUrl(PrescriptionItem $item): string
    {
        return "/api/prescriptions/{$item->prescription_id}/items/{$item->id}/promote-to-medication";
    }
}
