<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Consulta sem digitação" (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md): anamnese, comportamento,
 * contexto e conduta estruturados no `PUT professional/medical-records/{id}`.
 *
 * A primeira classe de teste aqui é uma REGRESSÃO deliberada: `anamnesis_signs`/
 * `treatment_actions` só voltaram a persistir depois de remover as regras
 * `anamnesis_signs.*.notes`/`treatment_actions.*.notes` do Form Request — a presença de
 * QUALQUER regra `campo.*.subchave` muda `FormRequest::validated()` para modo restritivo e
 * derruba silenciosamente as subchaves sem regra própria (`sign`, `onset`...), mesmo a
 * validação passando sem erro. Descoberto em teste manual via curl, não em leitura de código.
 */
class MedicalRecordStructuredConsultationTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Pet $dog;

    private Pet $cat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->dog = Pet::factory()->create(['user_id' => $tutor->id, 'species' => 'dog']);
        $this->cat = Pet::factory()->create(['user_id' => $tutor->id, 'species' => 'cat']);

        foreach ([$this->dog, $this->cat] as $pet) {
            PetVetAccess::create([
                'pet_id' => $pet->id,
                'veterinarian_id' => $this->professional->id,
                'granted_by' => $tutor->id,
                'access_level' => VetAccessLevel::WRITE,
                'status' => PetVetAccess::STATUS_ACCEPTED,
                'is_active' => true,
                'granted_at' => now(),
                'responded_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->professional);
    }

    public function test_update_persists_every_structured_field_without_dropping_sub_keys(): void
    {
        $record = $this->createDraft($this->dog);

        $response = $this->putJson("/api/professional/medical-records/{$record->id}", [
            'anamnesis_signs' => [
                ['sign' => 'vomiting', 'onset' => '1_3_days', 'evolution' => 'worsening', 'content' => 'bile'],
                ['sign' => 'lethargy', 'onset' => '1_3_days', 'notes' => 'só à noite'],
            ],
            'behavior_findings' => ['appetite' => 'reduced', 'sleep_pattern' => 'normal'],
            'recent_routine_change' => false,
            'context_flags' => ['recent_diet_change' => true, 'recent_diet_change_notes' => 'trocou de ração'],
            'treatment_actions' => [
                ['category' => 'medication_type', 'item' => 'antiemetic'],
                ['category' => 'tutor_guidance', 'item' => 'return_if_worsening'],
            ],
            'diagnosis_status' => 'presumptive',
        ]);

        $response->assertOk();

        $fresh = MedicalRecord::findOrFail($record->id);

        $this->assertSame('vomiting', $fresh->anamnesis_signs[0]['sign']);
        $this->assertSame('1_3_days', $fresh->anamnesis_signs[0]['onset']);
        $this->assertSame('bile', $fresh->anamnesis_signs[0]['content']);
        $this->assertSame('só à noite', $fresh->anamnesis_signs[1]['notes']);
        $this->assertSame(['appetite' => 'reduced', 'sleep_pattern' => 'normal'], $fresh->behavior_findings);
        $this->assertFalse($fresh->recent_routine_change);
        $this->assertTrue($fresh->context_flags['recent_diet_change']);
        $this->assertSame('antiemetic', $fresh->treatment_actions[0]['item']);
        $this->assertSame('presumptive', $fresh->diagnosis_status);
    }

    public function test_rejects_unknown_anamnesis_sign(): void
    {
        $record = $this->createDraft($this->dog);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'anamnesis_signs' => [['sign' => 'made_up_sign']],
        ])->assertStatus(422)->assertJsonValidationErrors('anamnesis_signs');
    }

    public function test_rejects_specific_modifier_on_the_wrong_sign(): void
    {
        $record = $this->createDraft($this->dog);

        // `limb` só é válido para `lameness`, não para `vomiting` (contrato §3.2).
        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'anamnesis_signs' => [['sign' => 'vomiting', 'limb' => 'front_right']],
        ])->assertStatus(422)->assertJsonValidationErrors('anamnesis_signs');
    }

    public function test_rejects_frequency_per_day_on_a_sign_that_does_not_support_it(): void
    {
        $record = $this->createDraft($this->dog);

        // `frequency_per_day` só vale para vomiting/diarrhea/cough_sneeze/seizure.
        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'anamnesis_signs' => [['sign' => 'lethargy', 'frequency_per_day' => 3]],
        ])->assertStatus(422)->assertJsonValidationErrors('anamnesis_signs');
    }

    public function test_rejects_unknown_behavior_findings_key(): void
    {
        $record = $this->createDraft($this->dog);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'behavior_findings' => ['bogus_key' => 'x'],
        ])->assertStatus(422)->assertJsonValidationErrors('behavior_findings');
    }

    public function test_rejects_unknown_context_flags_key(): void
    {
        $record = $this->createDraft($this->dog);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'context_flags' => ['bogus_key' => true],
        ])->assertStatus(422)->assertJsonValidationErrors('context_flags');
    }

    public function test_rejects_treatment_item_that_does_not_belong_to_its_category(): void
    {
        $record = $this->createDraft($this->dog);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'treatment_actions' => [['category' => 'medication_type', 'item' => 'xray']],
        ])->assertStatus(422)->assertJsonValidationErrors('treatment_actions');
    }

    public function test_rejects_invalid_diagnosis_status(): void
    {
        $record = $this->createDraft($this->dog);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'diagnosis_status' => 'confirmed',
        ])->assertStatus(422)->assertJsonValidationErrors('diagnosis_status');
    }

    /**
     * Erratum do contrato (docs/atendimento-veterinario/05-*.md §10): `weight_change` deixou
     * de ser exclusivo de cão.
     */
    public function test_weight_change_chief_complaint_is_now_valid_for_a_cat(): void
    {
        $record = $this->createDraft($this->cat);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'chief_complaint' => 'weight_change',
        ])->assertOk();
    }

    public function test_bad_breath_dental_chief_complaint_remains_dog_only(): void
    {
        $record = $this->createDraft($this->cat);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'chief_complaint' => 'bad_breath_dental',
        ])->assertStatus(422)->assertJsonValidationErrors('chief_complaint');
    }

    private function createDraft(Pet $pet): MedicalRecord
    {
        return MedicalRecord::create([
            'pet_id' => $pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }
}
