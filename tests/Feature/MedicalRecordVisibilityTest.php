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
 * Fronteira tutor/profissional (contrato §6) e correção do bug de `MedicalRecordPolicy`
 * (contrato §7): rascunho nunca aparece fora do autor; finalizado não se edita nem se apaga.
 *
 * Escrito, não executado via `artisan test` — validado manualmente via `curl`.
 */
class MedicalRecordVisibilityTest extends TestCase
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
            'access_level' => VetAccessLevel::READ,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
    }

    public function test_tutor_cannot_see_a_draft_record_of_their_own_pet(): void
    {
        $draft = $this->createRecord('draft');

        Sanctum::actingAs($this->tutor);
        $this->getJson("/api/medical-records/{$draft->id}")->assertForbidden();
    }

    public function test_authorized_vet_cannot_see_a_colleague_draft(): void
    {
        $draft = $this->createRecord('draft');

        Sanctum::actingAs($this->authorizedColleague());
        $this->getJson("/api/medical-records/{$draft->id}")->assertForbidden();
    }

    public function test_tutor_sees_a_finalized_record_with_summary_first(): void
    {
        $record = $this->createRecord('finalized', [
            'summary_for_tutor' => 'Resumo simples para o tutor.',
            'weight' => 10,
            'diagnosis' => 'Otite',
        ]);

        Sanctum::actingAs($this->tutor);
        $response = $this->getJson("/api/medical-records/{$record->id}");

        $response->assertOk();
        $keys = array_keys($response->json('data'));
        $this->assertSame('summary_for_tutor', $keys[0]);
    }

    public function test_finalized_records_list_for_pet_excludes_drafts(): void
    {
        $this->createRecord('draft');
        $finalized = $this->createRecord('finalized', ['weight' => 10, 'diagnosis' => 'Otite']);

        Sanctum::actingAs($this->tutor);
        $response = $this->getJson("/api/pets/{$this->pet->id}/medical-records");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$finalized->id], $ids);
    }

    public function test_update_is_denied_once_finalized_even_for_the_author(): void
    {
        $record = $this->createRecord('finalized', ['weight' => 10, 'diagnosis' => 'Otite']);

        Sanctum::actingAs($this->professional);
        $this->putJson("/api/professional/medical-records/{$record->id}", ['notes' => 'edição indevida'])
            ->assertForbidden();
    }

    public function test_delete_is_denied_once_finalized_even_for_the_author(): void
    {
        $record = $this->createRecord('finalized', ['weight' => 10, 'diagnosis' => 'Otite']);

        Sanctum::actingAs($this->professional);
        $this->deleteJson("/api/professional/medical-records/{$record->id}")->assertForbidden();
    }

    public function test_draft_can_still_be_discarded_by_its_author(): void
    {
        $draft = $this->createRecord('draft');

        Sanctum::actingAs($this->professional);
        $this->deleteJson("/api/professional/medical-records/{$draft->id}")->assertOk();
        $this->assertSoftDeleted('medical_records', ['id' => $draft->id]);
    }

    private function authorizedColleague(): User
    {
        $colleague = User::factory()->professional()->create();
        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $colleague->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::READ,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        return $colleague;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createRecord(string $status, array $extra = []): MedicalRecord
    {
        return MedicalRecord::create([...[
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => $status,
        ], ...$extra]);
    }
}
