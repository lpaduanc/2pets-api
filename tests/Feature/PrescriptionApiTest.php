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
 * Contrato de `/api/professional/prescriptions` — a tela de prescrições do profissional.
 *
 * Reescrito para o schema de `prescription_items` (contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §2), que substitui o antigo array
 * `prescriptions.medications` (JSON). O que estes testes travam, e por quê cada um existe:
 *   - `items` sai como lista estruturada, nunca a coluna JSON antiga.
 *   - `prescription_date`/`valid_until` saem em `Y-m-d`. ISO datetime deslocava a data por fuso.
 *   - o gate de privacidade do pet (`PetVetAccess`) continua valendo na escrita.
 *   - a prescrição só é editável/apagável ENQUANTO não emitida (imutabilidade, contrato §1).
 */
class PrescriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena Prado']);
        $this->tutor = User::factory()->tutor()->create(['name' => 'Maria Silva']);
        $this->pet = Pet::factory()->create([
            'user_id' => $this->tutor->id,
            'name' => 'Bella',
            'species' => 'dog',
        ]);

        $this->grantAccess(VetAccessLevel::WRITE);

        Sanctum::actingAs($this->professional);
    }

    public function test_index_returns_items_as_a_list_of_objects(): void
    {
        $this->createPrescription();

        $response = $this->getJson('/api/professional/prescriptions');

        $response->assertOk();
        $items = $response->json('data.0.items');

        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertSame('Amoxicilina', $items[0]['commercial_name']);
    }

    public function test_index_serializes_calendar_dates_without_time_or_timezone(): void
    {
        $this->createPrescription([
            'prescription_date' => '2026-03-05',
            'valid_until' => '2026-05-04',
        ]);

        $response = $this->getJson('/api/professional/prescriptions');

        $response->assertOk()
            ->assertJsonPath('data.0.prescription_date', '2026-03-05')
            ->assertJsonPath('data.0.valid_until', '2026-05-04');
    }

    public function test_index_embeds_the_pet_tutor(): void
    {
        $this->createPrescription();

        $response = $this->getJson('/api/professional/prescriptions');

        $response->assertOk()
            ->assertJsonPath('data.0.pet.name', 'Bella')
            ->assertJsonPath('data.0.pet.tutor.id', $this->tutor->id)
            ->assertJsonPath('data.0.pet.tutor.name', 'Maria Silva')
            ->assertJsonPath('data.0.professional.name', 'Dra. Helena Prado');
    }

    public function test_index_keeps_the_standard_pagination_envelope(): void
    {
        $this->createPrescription();

        $this->getJson('/api/professional/prescriptions')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_store_persists_a_structured_item_list(): void
    {
        $response = $this->postJson('/api/professional/prescriptions', $this->validPayload());

        $response->assertCreated()->assertJsonPath('data.items.0.commercial_name', 'Dipirona');

        $this->assertDatabaseHas('prescription_items', [
            'commercial_name' => 'Dipirona',
        ]);
    }

    public function test_store_rejects_an_item_without_a_name_and_keys_the_error_by_index(): void
    {
        $payload = $this->validPayload([
            'items' => [
                ['commercial_name' => 'Dipirona', 'dose_value' => 500, 'dose_unit' => 'mg'],
                ['dose_value' => 250, 'dose_unit' => 'mg'],
            ],
        ]);

        $this->postJson('/api/professional/prescriptions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.commercial_name']);
    }

    public function test_store_rejects_an_empty_item_list(): void
    {
        $this->postJson('/api/professional/prescriptions', $this->validPayload(['items' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_store_rejects_a_validity_date_before_the_prescription_date(): void
    {
        $payload = $this->validPayload([
            'prescription_date' => '2026-03-05',
            'valid_until' => '2026-03-04',
        ]);

        $this->postJson('/api/professional/prescriptions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    }

    public function test_store_requires_a_standalone_reason_when_there_is_no_medical_record(): void
    {
        $payload = $this->validPayload();
        unset($payload['standalone_reason']);

        $this->postJson('/api/professional/prescriptions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['standalone_reason']);
    }

    public function test_store_is_blocked_when_the_tutor_never_granted_access_to_the_pet(): void
    {
        $strangerPet = Pet::factory()->create(['user_id' => User::factory()->tutor()->create()->id]);

        $this->postJson('/api/professional/prescriptions', $this->validPayload(['pet_id' => $strangerPet->id]))
            ->assertStatus(403);

        $this->assertDatabaseCount('prescriptions', 0);
    }

    public function test_store_is_blocked_when_the_grant_is_read_only(): void
    {
        $this->grantAccess(VetAccessLevel::READ);

        $this->postJson('/api/professional/prescriptions', $this->validPayload())
            ->assertStatus(403);
    }

    public function test_update_replaces_the_item_list(): void
    {
        $prescription = $this->createPrescription();

        $this->putJson("/api/professional/prescriptions/{$prescription->id}", [
            'items' => [['commercial_name' => 'Meloxicam', 'dose_value' => 2, 'dose_unit' => 'mg']],
        ])->assertOk()->assertJsonPath('data.items.0.commercial_name', 'Meloxicam');

        $this->assertDatabaseCount('prescription_items', 1);
        $this->assertDatabaseHas('prescription_items', ['commercial_name' => 'Meloxicam']);
    }

    public function test_update_is_rejected_once_the_prescription_has_been_issued(): void
    {
        $prescription = $this->createPrescription(['standalone_reason' => 'remote_orientation']);
        $this->postJson("/api/professional/prescriptions/{$prescription->id}/issue")->assertOk();

        $this->putJson("/api/professional/prescriptions/{$prescription->id}", [
            'items' => [['commercial_name' => 'Meloxicam', 'dose_value' => 2, 'dose_unit' => 'mg']],
        ])->assertStatus(422);
    }

    public function test_destroy_is_rejected_once_the_prescription_has_been_issued(): void
    {
        $prescription = $this->createPrescription(['standalone_reason' => 'remote_orientation']);
        $this->postJson("/api/professional/prescriptions/{$prescription->id}/issue")->assertOk();

        $this->deleteJson("/api/professional/prescriptions/{$prescription->id}")->assertStatus(422);
    }

    public function test_issue_is_rejected_for_a_prescription_linked_to_a_medical_record(): void
    {
        $medicalRecord = \App\Models\MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $prescription = $this->createPrescription(['medical_record_id' => $medicalRecord->id]);

        $this->postJson("/api/professional/prescriptions/{$prescription->id}/issue")->assertStatus(422);
    }

    public function test_issue_stamps_issued_at_and_becomes_immutable(): void
    {
        $prescription = $this->createPrescription(['standalone_reason' => 'remote_orientation']);

        $response = $this->postJson("/api/professional/prescriptions/{$prescription->id}/issue");

        $response->assertOk()->assertJsonPath('data.is_editable', false);
        $this->assertNotNull($prescription->fresh()->issued_at);
    }

    public function test_cancel_requires_a_reason_and_only_applies_to_an_issued_prescription(): void
    {
        $prescription = $this->createPrescription(['standalone_reason' => 'remote_orientation']);

        $this->postJson("/api/professional/prescriptions/{$prescription->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/professional/prescriptions/{$prescription->id}/cancel", ['reason' => 'Erro de dose'])
            ->assertStatus(422);

        $this->postJson("/api/professional/prescriptions/{$prescription->id}/issue")->assertOk();

        $this->postJson("/api/professional/prescriptions/{$prescription->id}/cancel", ['reason' => 'Erro de dose'])
            ->assertOk()
            ->assertJsonPath('data.canceled_reason', 'Erro de dose');
    }

    public function test_status_filter_separates_valid_from_expired_prescriptions(): void
    {
        $current = $this->createPrescription(['valid_until' => now()->addMonth()->toDateString()]);
        $expired = $this->createPrescription(['valid_until' => now()->subMonth()->toDateString()]);

        $this->getJson('/api/professional/prescriptions?status=valid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->id);

        $this->getJson('/api/professional/prescriptions?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expired->id);

        $this->getJson('/api/professional/prescriptions?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_search_matches_pet_name_tutor_name_and_medication_name(): void
    {
        $this->createPrescription();

        foreach (['Bella', 'Maria', 'Amoxi'] as $term) {
            $this->getJson('/api/professional/prescriptions?search='.$term)
                ->assertOk()
                ->assertJsonCount(1, 'data', "Busca por '{$term}' não encontrou a prescrição.");
        }

        $this->getJson('/api/professional/prescriptions?search=inexistente')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_professional_cannot_read_this_prescription(): void
    {
        $prescription = $this->createPrescription();

        Sanctum::actingAs(User::factory()->professional()->create());

        $this->getJson("/api/professional/prescriptions/{$prescription->id}")->assertStatus(404);
    }

    public function test_prescription_pdf_is_downloadable_by_the_prescribing_professional(): void
    {
        $prescription = $this->createPrescription();

        $response = $this->get("/api/reports/prescription/{$prescription->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_prescription_pdf_is_denied_to_an_unrelated_professional(): void
    {
        $prescription = $this->createPrescription();

        Sanctum::actingAs(User::factory()->professional()->create());

        $this->getJson("/api/reports/prescription/{$prescription->id}/pdf")->assertStatus(403);
    }

    public function test_prescription_pdf_is_denied_to_the_tutor_before_the_prescription_is_issued(): void
    {
        $prescription = $this->createPrescription();

        Sanctum::actingAs($this->tutor);

        $this->getJson("/api/reports/prescription/{$prescription->id}/pdf")->assertStatus(404);
    }

    private function grantAccess(VetAccessLevel $level): void
    {
        PetVetAccess::updateOrCreate(
            ['pet_id' => $this->pet->id, 'veterinarian_id' => $this->professional->id],
            [
                'granted_by' => $this->tutor->id,
                'access_level' => $level,
                'status' => PetVetAccess::STATUS_ACCEPTED,
                'is_active' => true,
                'granted_at' => now(),
                'responded_at' => now(),
            ]
        );
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

        $prescription->items()->create([
            'position' => 1,
            'commercial_name' => 'Amoxicilina',
            'dose_value' => 250,
            'dose_unit' => 'mg',
            'frequency' => 'bid',
            'duration_text' => '7 dias',
        ]);

        return $prescription;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'pet_id' => $this->pet->id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'remote_orientation',
            'items' => [[
                'commercial_name' => 'Dipirona',
                'dose_value' => 500,
                'dose_unit' => 'mg',
                'frequency' => 'tid',
                'duration_text' => '5 dias',
            ]],
        ], $overrides);
    }
}
