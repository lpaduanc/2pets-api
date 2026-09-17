<?php

namespace Tests\Feature;

use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetWeightHistory;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Timeline agregada do pet — item 11 do MVP
 * (docs/atendimento-veterinario/00-dominio-e-escopo.md §6).
 *
 * Escrito, não executado via `artisan test` — validado manualmente via `curl`.
 */
class PetTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->professional = User::factory()->professional()->create();
        $this->pet = Pet::factory()->create([
            'user_id' => $this->tutor->id,
            'chronic_conditions' => ['Displasia coxofemoral'],
        ]);

        Sanctum::actingAs($this->tutor);
    }

    public function test_timeline_merges_finalized_records_vaccinations_and_weights_by_date(): void
    {
        MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => '2026-06-01',
            'status' => 'finalized',
            'weight' => 10,
            'diagnosis' => 'Check-up de rotina',
            'summary_for_tutor' => 'Tudo bem com o Rex.',
        ]);

        Vaccination::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'vaccine_name' => 'V10',
            'application_date' => '2026-07-01',
        ]);

        PetWeightHistory::create([
            'pet_id' => $this->pet->id,
            'measured_by_user_id' => $this->tutor->id,
            'weight' => 10.5,
            'measured_at' => '2026-08-01',
        ]);

        $response = $this->getJson("/api/pets/{$this->pet->id}/timeline");

        $response->assertOk();
        $types = collect($response->json('data'))->pluck('type')->all();

        // Ordem cronológica DESC: peso (ago) → vacina (jul) → atendimento (jun).
        $this->assertSame(['weight', 'vaccination', 'medical_record'], $types);
    }

    public function test_timeline_excludes_draft_medical_records(): void
    {
        MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $response = $this->getJson("/api/pets/{$this->pet->id}/timeline");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_timeline_exposes_current_pathologies_snapshot_separately(): void
    {
        $response = $this->getJson("/api/pets/{$this->pet->id}/timeline");

        $response->assertOk();
        $this->assertContains('Displasia coxofemoral', $response->json('pathologies'));
    }

    public function test_a_stranger_cannot_read_another_pets_timeline(): void
    {
        $stranger = User::factory()->tutor()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/pets/{$this->pet->id}/timeline")->assertForbidden();
    }
}
