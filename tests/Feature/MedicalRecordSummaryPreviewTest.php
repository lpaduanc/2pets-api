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
 * `POST professional/medical-records/{id}/summary-preview` — contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §6. Template
 * determinístico (sem IA); o endpoint só DEVOLVE o texto, nunca persiste.
 */
class MedicalRecordSummaryPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id, 'species' => 'dog', 'name' => 'Thor']);

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

    public function test_builds_a_readable_summary_from_fully_structured_fields(): void
    {
        $record = $this->draftWithStructuredFindings();

        $response = $this->postJson("/api/professional/medical-records/{$record->id}/summary-preview");

        $response->assertOk();
        $summary = $response->json('data.summary');

        $this->assertStringContainsString('Thor foi atendido(a) por vômito', $summary);
        $this->assertStringContainsString('com início há 1 a 3 dias', $summary);
        $this->assertStringContainsString('Também foram relatados: apatia', $summary);
        $this->assertStringContainsString('apetite reduzido', $summary);
        $this->assertStringContainsString('dor à palpação abdominal', $summary);
        $this->assertStringContainsString('hidratação levemente reduzida', $summary);
        $this->assertStringContainsString('O diagnóstico foi presuntivo: Gastroenterite.', $summary);
        $this->assertStringContainsString('prescrição de medicação para o estômago', $summary);
    }

    public function test_never_cites_a_finding_the_vet_did_not_mark(): void
    {
        $record = $this->createDraft();
        $record->update(['chief_complaint' => 'vomiting']);

        $summary = $this->postJson("/api/professional/medical-records/{$record->id}/summary-preview")
            ->assertOk()
            ->json('data.summary');

        // Só o vômito foi marcado — nenhum outro sinal, achado de exame ou conduta existe.
        $this->assertStringContainsString('vômito', $summary);
        $this->assertStringNotContainsString('diagnóstico', $summary);
        $this->assertStringNotContainsString('conduta', $summary);
        $this->assertStringNotContainsString('comportamento', $summary);
    }

    public function test_falls_back_to_a_minimal_honest_sentence_when_nothing_structured_was_filled(): void
    {
        $record = $this->createDraft();

        $summary = $this->postJson("/api/professional/medical-records/{$record->id}/summary-preview")
            ->assertOk()
            ->json('data.summary');

        $this->assertStringContainsString('Consulte os detalhes técnicos do atendimento.', $summary);
    }

    public function test_does_not_persist_anything(): void
    {
        $record = $this->draftWithStructuredFindings();

        $this->postJson("/api/professional/medical-records/{$record->id}/summary-preview")->assertOk();

        $this->assertNull(MedicalRecord::findOrFail($record->id)->summary_for_tutor);
    }

    public function test_a_colleague_without_access_cannot_preview_the_draft(): void
    {
        $record = $this->createDraft();
        $colleague = User::factory()->professional()->create();
        Sanctum::actingAs($colleague);

        $this->postJson("/api/professional/medical-records/{$record->id}/summary-preview")
            ->assertForbidden();
    }

    public function test_cannot_preview_a_finalized_record(): void
    {
        $record = $this->createDraft();
        $record->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $this->professional->id]);

        $this->postJson("/api/professional/medical-records/{$record->id}/summary-preview")
            ->assertForbidden();
    }

    private function createDraft(): MedicalRecord
    {
        return MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }

    private function draftWithStructuredFindings(): MedicalRecord
    {
        $record = $this->createDraft();

        $record->update([
            'chief_complaint' => 'vomiting',
            'hydration_status' => 'mild',
            'physical_exam' => ['digestive' => 'painful'],
            'anamnesis_signs' => [
                ['sign' => 'vomiting', 'onset' => '1_3_days', 'evolution' => 'worsening', 'content' => 'bile'],
                ['sign' => 'lethargy', 'onset' => '1_3_days'],
            ],
            'behavior_findings' => ['appetite' => 'reduced', 'sleep_pattern' => 'normal'],
            'diagnosis' => 'Gastroenterite',
            'diagnosis_status' => 'presumptive',
            'treatment_actions' => [
                ['category' => 'medication_type', 'item' => 'antiemetic'],
                ['category' => 'management_diet', 'item' => 'gastrointestinal_diet'],
                ['category' => 'tutor_guidance', 'item' => 'return_if_worsening'],
            ],
        ]);

        return $record;
    }
}
