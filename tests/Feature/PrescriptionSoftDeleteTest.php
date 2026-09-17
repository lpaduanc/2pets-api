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
 * `DELETE /professional/prescriptions/{id}` — receita é documento clínico com valor legal.
 *
 * Até aqui o `destroy()` fazia `DELETE` físico, apagando prova de prescrição (inclusive de
 * medicamento controlado) contra a regra "soft delete em tudo". Estes testes travam as duas
 * pontas: a linha continua no banco e some de tudo que a tela consulta.
 */
class PrescriptionSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->professional);
    }

    public function test_destroy_keeps_the_row_in_the_database_and_only_stamps_deleted_at(): void
    {
        $prescription = $this->createPrescription();

        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")->assertOk();

        $this->assertDatabaseHas('prescriptions', ['id' => $prescription->id]);
        $this->assertNotNull(
            Prescription::withTrashed()->find($prescription->id)?->deleted_at,
            'A receita precisa continuar arquivada, com `deleted_at` preenchido.'
        );
    }

    public function test_destroy_keeps_the_response_shape_the_screen_already_expects(): void
    {
        $prescription = $this->createPrescription();

        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Prescrição removida com sucesso!']);
    }

    public function test_a_deleted_prescription_disappears_from_the_list_and_from_the_tab_counter(): void
    {
        $kept = $this->createPrescription();
        $removed = $this->createPrescription();

        $this->deleteJson("/api/professional/prescriptions/{$removed->id}")->assertOk();

        $this->getJson('/api/professional/prescriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_deleted_prescription_disappears_from_the_status_counters_of_every_tab(): void
    {
        $this->createPrescription(['valid_until' => now()->addMonth()->toDateString()]);
        $expired = $this->createPrescription(['valid_until' => now()->subMonth()->toDateString()]);

        $this->deleteJson("/api/professional/prescriptions/{$expired->id}")->assertOk();

        $this->getJson('/api/professional/prescriptions?status=all')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/professional/prescriptions?status=valid')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/professional/prescriptions?status=expired')->assertJsonPath('meta.total', 0);
    }

    public function test_a_deleted_prescription_disappears_from_the_valid_prescriptions_endpoint(): void
    {
        $prescription = $this->createPrescription(['valid_until' => now()->addMonth()->toDateString()]);

        $this->getJson('/api/professional/prescriptions/valid')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")->assertOk();

        $this->getJson('/api/professional/prescriptions/valid')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_deleted_prescription_is_no_longer_readable_editable_or_deletable(): void
    {
        $prescription = $this->createPrescription();

        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")->assertOk();

        $this->getJson("/api/professional/prescriptions/{$prescription->id}")->assertStatus(404);
        $this->putJson("/api/professional/prescriptions/{$prescription->id}", [
            'items' => [['commercial_name' => 'Meloxicam', 'dose_value' => 2, 'dose_unit' => 'mg']],
        ])->assertStatus(404);
        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")->assertStatus(404);
    }

    public function test_the_pdf_of_a_deleted_prescription_is_no_longer_downloadable(): void
    {
        $prescription = $this->createPrescription();

        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")->assertOk();

        $this->getJson("/api/reports/prescription/{$prescription->id}/pdf")->assertStatus(404);
    }

    public function test_searching_and_filtering_never_bring_a_deleted_prescription_back(): void
    {
        $prescription = $this->createPrescription();

        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")->assertOk();

        $this->getJson('/api/professional/prescriptions?search=Amoxi')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/professional/prescriptions?pet_id={$this->pet->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPrescription(array $overrides = []): Prescription
    {
        $prescription = Prescription::create(array_merge([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'prescription_date' => now()->toDateString(),
        ], $overrides));

        $prescription->items()->create(['position' => 1, 'commercial_name' => 'Amoxicilina', 'dose_value' => 250, 'dose_unit' => 'mg']);

        return $prescription;
    }
}
