<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Exceptions\Hospitalization\HospitalizationCareLogImmutableException;
use App\Models\Hospitalization;
use App\Models\HospitalizationCareLog;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Checklist de cuidados da internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4/§7.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`
 * (verificado ao vivo via `curl` contra o ambiente de dev — ver relato da tarefa).
 */
class HospitalizationCareLogTest extends TestCase
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

    public function test_done_status_does_not_require_notes(): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/care-logs", [
            'care_type' => 'feeding',
            'status' => 'done',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'done');
    }

    /** §4.3: decisão deliberada — motivo obrigatório quando o cuidado não foi feito. */
    public function test_not_done_without_notes_is_rejected(): void
    {
        $hospitalization = $this->admit();

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/care-logs", [
            'care_type' => 'feeding',
            'status' => 'not_done',
        ])->assertStatus(422)->assertJsonValidationErrors('notes');
    }

    public function test_not_done_with_notes_is_accepted(): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/care-logs", [
            'care_type' => 'feeding',
            'status' => 'not_done',
            'notes' => 'Animal recusou a dieta oferecida.',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.notes', 'Animal recusou a dieta oferecida.');
    }

    public function test_not_applicable_does_not_require_notes(): void
    {
        $hospitalization = $this->admit();

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/care-logs", [
            'care_type' => 'medication_administration',
            'status' => 'not_applicable',
        ])->assertStatus(201);
    }

    /** §4.1: liga o cuidado ao item de fato prescrito, quando pertence à mesma internação. */
    public function test_medication_administration_links_a_prescription_item_from_the_same_stay(): void
    {
        $hospitalization = $this->admit();
        $item = $this->prescriptionItemFor($hospitalization->appointment_id);

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/care-logs", [
            'care_type' => 'medication_administration',
            'status' => 'done',
            'prescription_item_id' => $item->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.prescription_item_id', $item->id);
    }

    public function test_a_prescription_item_from_another_stay_is_rejected(): void
    {
        $hospitalization = $this->admit();
        $otherAppointmentId = $this->admit()->appointment_id;
        $item = $this->prescriptionItemFor($otherAppointmentId);

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/care-logs", [
            'care_type' => 'medication_administration',
            'status' => 'done',
            'prescription_item_id' => $item->id,
        ])->assertStatus(422)->assertJsonValidationErrors('prescription_item_id');
    }

    public function test_care_log_is_rejected_when_the_hospitalization_is_not_active(): void
    {
        $hospitalization = $this->admit();
        $this->putJson("/api/professional/hospitalizations/{$hospitalization->id}", [
            'status' => 'discharged',
            'discharge_date' => now()->toDateString(),
            'discharge_summary' => 'Alta sem intercorrências.',
        ])->assertOk();

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/care-logs", [
            'care_type' => 'feeding',
            'status' => 'done',
        ])->assertStatus(422);
    }

    public function test_the_model_itself_rejects_update_and_delete(): void
    {
        $hospitalization = $this->admit();
        $careLog = HospitalizationCareLog::create([
            'hospitalization_id' => $hospitalization->id,
            'author_id' => $this->vet->id,
            'care_type' => 'feeding',
            'status' => 'done',
        ]);

        $this->expectException(HospitalizationCareLogImmutableException::class);
        $careLog->update(['status' => 'not_done']);
    }

    public function test_table_has_no_updated_at_or_deleted_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('hospitalization_care_logs', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('hospitalization_care_logs', 'deleted_at'));
    }

    private function prescriptionItemFor(int $appointmentId): PrescriptionItem
    {
        $prescription = Prescription::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'appointment_id' => $appointmentId,
            'prescription_date' => now()->toDateString(),
            'kind' => 'simple',
        ]);

        return $prescription->items()->create([
            'active_ingredient' => 'Dipirona',
            'dose_value' => 25,
            'dose_unit' => 'mg/kg',
            'frequency' => 'bid',
        ]);
    }

    private function admit(): Hospitalization
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação de teste',
        ]);

        $response->assertStatus(201);

        return Hospitalization::findOrFail($response->json('data.id'));
    }
}
