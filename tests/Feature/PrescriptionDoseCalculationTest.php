<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\PetWeightHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cálculo de dose por peso — contrato docs/atendimento-veterinario/03-contrato-receituario.md
 * §3. Três regras inegociáveis travadas aqui: é sugestão (nunca trava), nunca impede
 * prescrever, e `dose_value` explícito do vet sempre vence o cálculo.
 */
class PrescriptionDoseCalculationTest extends TestCase
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

        Sanctum::actingAs($this->professional);
    }

    public function test_calculates_dose_from_medical_record_weight_when_dose_value_is_omitted(): void
    {
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
            'weight' => 10,
        ]);

        $response = $this->postJson('/api/professional/prescriptions', [
            'pet_id' => $this->pet->id,
            'medical_record_id' => $record->id,
            'prescription_date' => now()->toDateString(),
            'items' => [[
                'commercial_name' => 'Meloxicam',
                'dose_per_kg' => 0.2,
                'dose_unit' => 'mg',
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.items.0.dose_value', 2.0)
            ->assertJsonPath('data.items.0.dose_calculated', true);
    }

    public function test_does_not_calculate_when_the_pet_weight_is_unknown_and_never_fails(): void
    {
        $response = $this->postJson('/api/professional/prescriptions', [
            'pet_id' => $this->pet->id,
            'standalone_reason' => 'remote_orientation',
            'prescription_date' => now()->toDateString(),
            'items' => [[
                'commercial_name' => 'Meloxicam',
                'dose_per_kg' => 0.2,
                'dose_unit' => 'mg',
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.items.0.dose_value', null)
            ->assertJsonPath('data.items.0.dose_calculated', false);
    }

    public function test_standalone_prescription_uses_the_latest_pet_weight_history(): void
    {
        PetWeightHistory::create([
            'pet_id' => $this->pet->id,
            'measured_by_user_id' => $this->professional->id,
            'weight' => 20,
            'measured_at' => now()->subDays(10),
        ]);
        PetWeightHistory::create([
            'pet_id' => $this->pet->id,
            'measured_by_user_id' => $this->professional->id,
            'weight' => 25,
            'measured_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/professional/prescriptions', [
            'pet_id' => $this->pet->id,
            'standalone_reason' => 'remote_orientation',
            'prescription_date' => now()->toDateString(),
            'items' => [[
                'commercial_name' => 'Meloxicam',
                'dose_per_kg' => 0.1,
                'dose_unit' => 'mg',
            ]],
        ]);

        // Usa o registro MAIS RECENTE (25 kg), não o mais antigo (20 kg).
        $response->assertCreated()->assertJsonPath('data.items.0.dose_value', 2.5);
    }

    public function test_an_explicit_dose_value_always_wins_over_the_calculation(): void
    {
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
            'weight' => 10,
        ]);

        $response = $this->postJson('/api/professional/prescriptions', [
            'pet_id' => $this->pet->id,
            'medical_record_id' => $record->id,
            'prescription_date' => now()->toDateString(),
            'items' => [[
                'commercial_name' => 'Meloxicam',
                'dose_per_kg' => 0.2,
                'dose_value' => 99,
                'dose_unit' => 'mg',
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.items.0.dose_value', 99.0)
            ->assertJsonPath('data.items.0.dose_calculated', false);
    }
}
