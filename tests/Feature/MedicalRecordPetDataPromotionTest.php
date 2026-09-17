<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §D:
 * `reported_pet_data` (o que o tutor informou NA consulta) só chega ao cadastro do pet por
 * ação explícita do TUTOR, sobre prontuário `finalized`.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class MedicalRecordPetDataPromotionTest extends TestCase
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
        $this->pet = Pet::factory()->create([
            'user_id' => $this->tutor->id,
            'weight' => 10.0,
            'docile_with_strangers' => 'yes',
        ]);
    }

    public function test_apply_to_pet_is_denied_to_anyone_but_the_tutor(): void
    {
        $record = $this->finalizedRecordWithReportedData();

        Sanctum::actingAs($this->professional);
        $this->postJson("/api/medical-records/{$record->id}/apply-to-pet")->assertForbidden();
    }

    public function test_apply_to_pet_is_denied_while_the_record_is_still_a_draft(): void
    {
        $record = $this->draftRecordWithReportedData();

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/medical-records/{$record->id}/apply-to-pet")->assertStatus(422);
        $this->assertSame(10.0, (float) $this->pet->fresh()->weight);
    }

    public function test_apply_to_pet_updates_the_pet_and_stamps_who_applied_it(): void
    {
        $record = $this->finalizedRecordWithReportedData();

        Sanctum::actingAs($this->tutor);
        $response = $this->postJson("/api/medical-records/{$record->id}/apply-to-pet");

        $response->assertOk();
        $changedFields = collect($response->json('data.changes'))->pluck('field')->all();
        $this->assertContains('weight_kg', $changedFields);
        $this->assertContains('docile_with_strangers', $changedFields);

        $freshPet = $this->pet->fresh();
        $this->assertSame(15.5, (float) $freshPet->weight);
        $this->assertSame('depends', $freshPet->docile_with_strangers);

        $freshRecord = $record->fresh();
        $this->assertNotNull($freshRecord->reported_pet_data_applied_at);
        $this->assertSame($this->tutor->id, $freshRecord->reported_pet_data_applied_by);
    }

    public function test_apply_to_pet_is_idempotent(): void
    {
        $record = $this->finalizedRecordWithReportedData();

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/medical-records/{$record->id}/apply-to-pet")->assertOk();
        $second = $this->postJson("/api/medical-records/{$record->id}/apply-to-pet");

        $second->assertOk();
        $this->assertSame([], $second->json('data.changes'));
    }

    public function test_apply_to_pet_accepts_a_subset_of_fields(): void
    {
        $record = $this->finalizedRecordWithReportedData();

        Sanctum::actingAs($this->tutor);
        $response = $this->postJson("/api/medical-records/{$record->id}/apply-to-pet", [
            'fields' => ['weight_kg'],
        ]);

        $response->assertOk();
        $this->assertSame(15.5, (float) $this->pet->fresh()->weight);
        $this->assertSame('yes', $this->pet->fresh()->docile_with_strangers);
    }

    public function test_pet_data_diff_lists_fields_that_differ_from_the_registered_pet(): void
    {
        $record = $this->finalizedRecordWithReportedData();

        Sanctum::actingAs($this->tutor);
        $response = $this->getJson("/api/medical-records/{$record->id}/pet-data-diff");

        $response->assertOk();
        $entries = collect($response->json('data'))->keyBy('field');
        $this->assertTrue($entries['weight_kg']['differs']);
        $this->assertSame(10.0, $entries['weight_kg']['current_value']);
        $this->assertSame(15.5, $entries['weight_kg']['reported_value']);
    }

    /** `reported_pet_data` nunca escreve em `pets` fora do fluxo explícito de promoção. */
    public function test_saving_the_draft_with_reported_pet_data_never_touches_the_pets_table(): void
    {
        $appointment = $this->createAppointment();
        Sanctum::actingAs($this->professional);
        $start = $this->postJson("/api/professional/appointments/{$appointment->id}/start");
        $recordId = $start->json('data.medical_record.id');

        $this->putJson("/api/professional/medical-records/{$recordId}", [
            'reported_pet_data' => ['weight_kg' => 99.9],
        ])->assertOk();

        $this->assertSame(10.0, (float) $this->pet->fresh()->weight);
    }

    /** Chave desconhecida em `reported_pet_data` é rejeitada, nunca ignorada em silêncio. */
    public function test_unknown_reported_pet_data_key_is_rejected(): void
    {
        $appointment = $this->createAppointment();
        Sanctum::actingAs($this->professional);
        $start = $this->postJson("/api/professional/appointments/{$appointment->id}/start");
        $recordId = $start->json('data.medical_record.id');

        $this->putJson("/api/professional/medical-records/{$recordId}", [
            'reported_pet_data' => ['not_a_real_field' => 'x'],
        ])->assertStatus(422);
    }

    /**
     * Campo em branco no relato NAO apaga o cadastro. `ReportedPetDataNormalizer` impede o
     * vazio de ser gravado desde 2026-09-16, mas registro antigo ainda pode carrega-lo — e
     * `diff`/`apply` decidiam o que promover por PRESENCA de chave, entao um
     * `food_brand: null` guardado zeraria a coluna do pet na hora em que o tutor aceitasse.
     */
    public function test_blank_reported_values_never_overwrite_the_registered_pet(): void
    {
        $this->pet->update(['food_brand' => 'Royal Canin', 'docile_with_strangers' => 'yes']);

        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->professional->id,
            'reported_pet_data' => [
                'weight_kg' => 15.5,
                'food_brand' => null,
                'docile_with_strangers' => '',
                'chronic_conditions' => [],
            ],
        ]);

        Sanctum::actingAs($this->tutor);
        $diff = $this->getJson("/api/medical-records/{$record->id}/pet-data-diff");
        $diff->assertOk();
        $diffFields = collect($diff->json('data'))->pluck('field')->all();
        $this->assertSame(['weight_kg'], $diffFields);

        $this->postJson("/api/medical-records/{$record->id}/apply-to-pet")->assertOk();

        $freshPet = $this->pet->fresh();
        $this->assertSame(15.5, (float) $freshPet->weight);
        $this->assertSame('Royal Canin', $freshPet->food_brand);
        $this->assertSame('yes', $freshPet->docile_with_strangers);
    }

    private function createAppointment(): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'confirmed',
        ]);
    }

    private function draftRecordWithReportedData(): MedicalRecord
    {
        return MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
            'weight' => 15.5,
            'diagnosis' => 'Otite',
            'reported_pet_data' => ['weight_kg' => 15.5, 'docile_with_strangers' => 'depends'],
        ]);
    }

    private function finalizedRecordWithReportedData(): MedicalRecord
    {
        $record = $this->draftRecordWithReportedData();
        $record->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $this->professional->id]);

        return $record;
    }
}
