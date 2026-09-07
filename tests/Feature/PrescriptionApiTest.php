<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato de `/api/professional/prescriptions` — a tela de prescrições do profissional.
 *
 * O que estes testes travam, e por quê cada um existe:
 *   - `medications` sai como lista de objetos. Já saiu como string (duplo-encode entre o
 *     `json_encode` do controller e o cast `array` do model) e o cliente iterava os caracteres.
 *   - `prescription_date`/`valid_until` saem em `Y-m-d`. ISO datetime deslocava a data por fuso.
 *   - o gate de privacidade do pet (`PetVetAccess`) continua valendo na escrita.
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

    public function test_index_returns_medications_as_a_list_of_objects(): void
    {
        $this->createPrescription();

        $response = $this->getJson('/api/professional/prescriptions');

        $response->assertOk();
        $medications = $response->json('data.0.medications');

        $this->assertIsArray($medications);
        $this->assertCount(1, $medications);
        $this->assertSame('Amoxicilina', $medications[0]['name']);
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

    public function test_index_recovers_a_legacy_double_encoded_row(): void
    {
        $prescription = $this->createPrescription();

        // Reproduz exatamente o que o controller antigo gravava: string JSON dentro de JSON.
        DB::table('prescriptions')->where('id', $prescription->id)->update([
            'medications' => json_encode(json_encode([['name' => 'Legado', 'dosage' => '1mg']])),
        ]);

        $response = $this->getJson('/api/professional/prescriptions');

        $response->assertOk()->assertJsonPath('data.0.medications.0.name', 'Legado');
    }

    public function test_store_persists_medications_as_a_json_array_not_a_string(): void
    {
        $response = $this->postJson('/api/professional/prescriptions', $this->validPayload());

        $response->assertCreated()->assertJsonPath('data.medications.0.name', 'Dipirona');

        $stored = DB::table('prescriptions')->value('medications');

        $this->assertIsArray(json_decode($stored, true), 'A coluna guardou string em vez de array.');
    }

    public function test_store_rejects_a_medication_without_a_name_and_keys_the_error_by_index(): void
    {
        $payload = $this->validPayload([
            'medications' => [
                ['name' => 'Dipirona', 'dosage' => '500mg', 'frequency' => '8/8h'],
                ['dosage' => '250mg', 'frequency' => '12/12h'],
            ],
        ]);

        $this->postJson('/api/professional/prescriptions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['medications.1.name']);
    }

    public function test_store_rejects_an_empty_medication_list(): void
    {
        $this->postJson('/api/professional/prescriptions', $this->validPayload(['medications' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['medications']);
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

    public function test_update_keeps_medications_as_an_array(): void
    {
        $prescription = $this->createPrescription();

        $this->putJson("/api/professional/prescriptions/{$prescription->id}", [
            'medications' => [['name' => 'Meloxicam', 'dosage' => '2mg', 'frequency' => '24/24h']],
        ])->assertOk()->assertJsonPath('data.medications.0.name', 'Meloxicam');

        $stored = DB::table('prescriptions')->where('id', $prescription->id)->value('medications');

        $this->assertIsArray(json_decode($stored, true));
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
        return Prescription::create(array_merge([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'prescription_date' => now()->toDateString(),
            'medications' => [[
                'name' => 'Amoxicilina',
                'dosage' => '250mg',
                'frequency' => '12/12h',
                'duration' => '7 dias',
            ]],
        ], $overrides));
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
            'medications' => [[
                'name' => 'Dipirona',
                'dosage' => '500mg',
                'frequency' => '8/8h',
                'duration' => '5 dias',
            ]],
        ], $overrides);
    }
}
