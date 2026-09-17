<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Adendo append-only — Res. CFMV nº 1.321/2020 alt. 1.653/2025
 * (docs/atendimento-veterinario/00-dominio-e-escopo.md §1.5).
 *
 * Escrito, não executado via `artisan test` (regra do projeto) — validado manualmente via
 * `curl` contra a API em execução.
 */
class MedicalRecordAddendumTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $tutor;

    private Pet $pet;

    private MedicalRecord $finalizedRecord;

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

        $this->finalizedRecord = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->professional->id,
            'weight' => 10,
            'diagnosis' => 'Otite',
        ]);

        Sanctum::actingAs($this->professional);
    }

    public function test_author_can_add_an_addendum_to_a_finalized_record(): void
    {
        $response = $this->postJson(
            "/api/professional/medical-records/{$this->finalizedRecord->id}/addenda",
            ['body' => 'Paciente retornou com melhora completa.']
        );

        $response->assertCreated()->assertJsonPath('data.body', 'Paciente retornou com melhora completa.');
        $this->assertDatabaseHas('medical_record_addenda', [
            'medical_record_id' => $this->finalizedRecord->id,
            'author_id' => $this->professional->id,
        ]);
    }

    public function test_addendum_is_rejected_while_the_record_is_still_a_draft(): void
    {
        $draft = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $this->postJson("/api/professional/medical-records/{$draft->id}/addenda", ['body' => 'texto'])
            ->assertForbidden();
    }

    public function test_a_colleague_with_pet_vet_access_cannot_add_an_addendum(): void
    {
        $colleague = User::factory()->professional()->create();
        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $colleague->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::FULL,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
        Sanctum::actingAs($colleague);

        $this->postJson("/api/professional/medical-records/{$this->finalizedRecord->id}/addenda", ['body' => 'texto'])
            ->assertForbidden();
    }

    public function test_addendum_table_has_no_updated_at_or_deleted_at_column(): void
    {
        // Write-once por desenho (contrato §2): não há coluna para editar nem para
        // soft-deletar um adendo — a única forma de "corrigir" é criar um adendo novo.
        $this->assertFalse(Schema::hasColumn('medical_record_addenda', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('medical_record_addenda', 'deleted_at'));
    }

    public function test_two_addenda_from_the_same_author_both_persist_independently(): void
    {
        $this->postJson(
            "/api/professional/medical-records/{$this->finalizedRecord->id}/addenda",
            ['body' => 'Primeiro adendo.']
        )->assertCreated();

        $this->postJson(
            "/api/professional/medical-records/{$this->finalizedRecord->id}/addenda",
            ['body' => 'Segundo adendo, corrigindo o primeiro.']
        )->assertCreated();

        $this->assertSame(2, $this->finalizedRecord->addenda()->count());
        $this->assertDatabaseHas('medical_record_addenda', ['body' => 'Primeiro adendo.']);
        $this->assertDatabaseHas('medical_record_addenda', ['body' => 'Segundo adendo, corrigindo o primeiro.']);
    }
}
