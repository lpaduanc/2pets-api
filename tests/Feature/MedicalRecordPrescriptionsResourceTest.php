<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `MedicalRecordResource.prescriptions` — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §2 "Decisão de nome no contrato de
 * resposta".
 *
 * O frontend-specialist apontou que `record.prescriptions` continuava sendo a coluna JSON
 * legada (`MedicalRecord::$casts`), então a seção de receituário estruturado da tela de
 * atendimento vinha sempre vazia. Estes testes travam a correção: `prescriptions` agora é a
 * lista de `Prescription` estruturada (via `linkedPrescriptions()`, nome escolhido porque um
 * atributo do Eloquent tem precedência sobre uma relação de mesmo nome); o texto antigo
 * migrou para `legacy_prescriptions`, somente leitura.
 */
class MedicalRecordPrescriptionsResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);
    }

    public function test_prescriptions_returns_structured_prescriptions_with_their_items(): void
    {
        $record = $this->createDraftRecord();
        $prescription = Prescription::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'medical_record_id' => $record->id,
            'prescription_date' => now()->toDateString(),
        ]);
        $prescription->items()->create([
            'position' => 1,
            'commercial_name' => 'Amoxicilina',
            'dose_value' => 250,
            'dose_unit' => 'mg',
        ]);

        $response = $this->getJson("/api/medical-records/{$record->id}");

        $response->assertOk()
            ->assertJsonPath('data.prescriptions.0.id', $prescription->id)
            ->assertJsonPath('data.prescriptions.0.items.0.commercial_name', 'Amoxicilina');
    }

    public function test_legacy_prescriptions_keeps_exposing_the_old_free_text_column(): void
    {
        $record = $this->createDraftRecord();
        DB::table('medical_records')->where('id', $record->id)->update([
            'prescriptions' => json_encode(['Amoxicilina 250mg 12/12h por 7 dias']),
        ]);

        $response = $this->getJson("/api/medical-records/{$record->id}");

        $response->assertOk()
            ->assertJsonPath('data.legacy_prescriptions.0', 'Amoxicilina 250mg 12/12h por 7 dias')
            ->assertJsonPath('data.prescriptions', []);
    }

    public function test_a_record_without_any_prescription_returns_an_empty_structured_list(): void
    {
        $record = $this->createDraftRecord();

        $this->getJson("/api/medical-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.prescriptions', [])
            ->assertJsonPath('data.legacy_prescriptions', null);
    }

    private function createDraftRecord(): MedicalRecord
    {
        return MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }
}
