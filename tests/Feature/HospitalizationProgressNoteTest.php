<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Exceptions\Hospitalization\HospitalizationProgressNoteImmutableException;
use App\Models\Hospitalization;
use App\Models\HospitalizationProgressNote;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Evolução diária da internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1/§7.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`
 * (verificado ao vivo via `curl` contra o ambiente de dev — ver relato da tarefa).
 */
class HospitalizationProgressNoteTest extends TestCase
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

    /** §1.1: nenhum sinal vital é obrigatório — só narrativa. */
    public function test_body_only_is_accepted_without_any_vital(): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes", [
            'body' => 'Animal calmo, aceitou dieta, sem intercorrências.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.body', 'Animal calmo, aceitou dieta, sem intercorrências.')
            ->assertJsonPath('data.temperature', null)
            ->assertJsonPath('data.author.id', $this->vet->id);
    }

    public function test_missing_body_is_rejected(): void
    {
        $hospitalization = $this->admit();

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    /** §1.1: peso informado alimenta o histórico de peso do pet. */
    public function test_weight_creates_a_pet_weight_history_entry(): void
    {
        $hospitalization = $this->admit();

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes", [
            'body' => 'Pesagem de rotina.',
            'weight' => 4.2,
        ])->assertStatus(201);

        $this->assertDatabaseHas('pet_weight_history', [
            'pet_id' => $this->pet->id,
            'measured_by_user_id' => $this->vet->id,
            'weight' => 4.2,
        ]);
    }

    /**
     * §1.3: correção é uma entrada NOVA — a original continua no histórico, inalterada, e
     * não precisa ser o mesmo autor.
     */
    public function test_a_correction_by_a_different_colleague_points_to_the_original_without_removing_it(): void
    {
        // `hasClinicalAccessToOrganization` depende de `medical-records.create` estar de
        // fato sincronizada ao papel — `Role::findOrCreate` (usado por
        // `User::factory()->professional()`) cria o papel VAZIO se o seeder nunca rodou.
        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $this->vet->id]);
        $colleague = User::factory()->professional()->create();
        OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $colleague->id]);

        $hospitalization = $this->admit();

        $original = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes", [
            'body' => 'Peso: 42kg (erro de digitação).',
        ])->json('data.id');

        Sanctum::actingAs($colleague);

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes", [
            'body' => 'Correção: o peso registrado às 08h estava errado, era 4,2kg.',
            'corrects_id' => $original,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.corrects_id', $original);
        $this->assertSame(2, HospitalizationProgressNote::count());
        $this->assertDatabaseHas('hospitalization_progress_notes', ['id' => $original, 'body' => 'Peso: 42kg (erro de digitação).']);
    }

    public function test_progress_note_is_rejected_when_the_hospitalization_is_not_active(): void
    {
        $hospitalization = $this->admit();
        $this->putJson("/api/professional/hospitalizations/{$hospitalization->id}", [
            'status' => 'discharged',
            'discharge_date' => now()->toDateString(),
            'discharge_summary' => 'Alta sem intercorrências.',
        ])->assertOk();

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes", [
            'body' => 'Tarde demais.',
        ])->assertStatus(422);
    }

    /** Contrato §1.3: sem rota de update/destroy — a imutabilidade central do requisito. */
    public function test_no_update_or_destroy_route_exists_for_progress_notes(): void
    {
        $hospitalization = $this->admit();
        $noteId = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes", [
            'body' => 'Entrada original.',
        ])->json('data.id');

        $this->putJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes/{$noteId}", ['body' => 'tentativa'])
            ->assertStatus(404);
        $this->deleteJson("/api/professional/hospitalizations/{$hospitalization->id}/progress-notes/{$noteId}")
            ->assertStatus(404);
    }

    /**
     * A imutabilidade não depende só da rota ausente — está travada no MODEL
     * (`HospitalizationProgressNote::booted()`), para qualquer caminho de escrita futuro.
     */
    public function test_the_model_itself_rejects_update_and_delete(): void
    {
        $hospitalization = $this->admit();
        $note = HospitalizationProgressNote::create([
            'hospitalization_id' => $hospitalization->id,
            'author_id' => $this->vet->id,
            'body' => 'Entrada original.',
        ]);

        $this->expectException(HospitalizationProgressNoteImmutableException::class);
        $note->update(['body' => 'Tentativa de reescrever.']);
    }

    public function test_the_model_itself_rejects_delete(): void
    {
        $hospitalization = $this->admit();
        $note = HospitalizationProgressNote::create([
            'hospitalization_id' => $hospitalization->id,
            'author_id' => $this->vet->id,
            'body' => 'Entrada original.',
        ]);

        $this->expectException(HospitalizationProgressNoteImmutableException::class);
        $note->delete();
    }

    public function test_table_has_no_updated_at_or_deleted_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('hospitalization_progress_notes', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('hospitalization_progress_notes', 'deleted_at'));
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
