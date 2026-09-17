<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Hospitalization;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RBAC por organização do módulo clínico de internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §6.
 *
 * Bug corrigido: `HospitalizationController` restringia `index`/`show`/`update` a
 * `professional_id = usuário autenticado`, sem nenhuma verificação de organização — um
 * colega `clinic_vet` da mesma clínica não conseguia nem LER a internação admitida por
 * outro colega.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`
 * (verificado ao vivo via `curl` contra o ambiente de dev — ver relato da tarefa).
 */
class HospitalizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admittingVet;

    private User $tutor;

    private Pet $pet;

    private Hospitalization $hospitalization;

    protected function setUp(): void
    {
        parent::setUp();

        // `hasClinicalAccessToOrganization` depende de `medical-records.create` estar de
        // fato sincronizada ao papel — `Role::findOrCreate` (usado por
        // `User::factory()->professional()`) cria o papel VAZIO se o seeder nunca rodou.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admittingVet = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->admittingVet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->admittingVet);
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação de teste',
        ]);
        $response->assertStatus(201);
        $this->hospitalization = Hospitalization::findOrFail($response->json('data.id'));
    }

    /** §6.2: colega ativo da MESMA organização, com `medical-records.create`, pode ler. */
    public function test_colleague_in_the_same_organization_can_view_a_hospitalization_admitted_by_another_vet(): void
    {
        $colleague = $this->attachColleagueToAdmittingVetsOrganization();
        Sanctum::actingAs($colleague);

        $this->getJson("/api/professional/hospitalizations/{$this->hospitalization->id}")->assertOk();
        $this->getJson('/api/professional/hospitalizations')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->hospitalization->id]);
    }

    public function test_colleague_in_the_same_organization_can_write_a_progress_note_and_a_care_log(): void
    {
        $colleague = $this->attachColleagueToAdmittingVetsOrganization();
        Sanctum::actingAs($colleague);

        $this->postJson("/api/professional/hospitalizations/{$this->hospitalization->id}/progress-notes", [
            'body' => 'Evolução do plantão da tarde.',
        ])->assertStatus(201);

        $this->postJson("/api/professional/hospitalizations/{$this->hospitalization->id}/care-logs", [
            'care_type' => 'feeding',
            'status' => 'done',
        ])->assertStatus(201);
    }

    /** §6.2: "lançar diária/item" também se estende ao colega — não só ver/escrever evolução. */
    public function test_colleague_in_the_same_organization_can_launch_a_daily_charge(): void
    {
        $colleague = $this->attachColleagueToAdmittingVetsOrganization();
        Sanctum::actingAs($colleague);

        $this->postJson("/api/professional/appointments/{$this->hospitalization->appointment_id}/charges", [
            'description' => 'Diária de internação',
            'unit_price' => 200,
        ])->assertStatus(201);
    }

    public function test_colleague_in_a_different_organization_is_forbidden(): void
    {
        $otherOrganization = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $otherOrganization->id,
            'user_id' => $stranger->id,
        ]);
        Sanctum::actingAs($stranger);

        $this->getJson("/api/professional/hospitalizations/{$this->hospitalization->id}")->assertStatus(403);
    }

    /**
     * `clinic_owner` nunca tem `medical-records.create` — continua sem escrever registro
     * clínico, mesma regra de "conta-clínica não é autora clínica" já valendo no projeto.
     */
    public function test_clinic_owner_of_the_same_organization_cannot_write_a_progress_note(): void
    {
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->admittingVet->id,
        ]);
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('clinic_owner', 'web'));
        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/professional/hospitalizations/{$this->hospitalization->id}/progress-notes", [
            'body' => 'Tentativa indevida.',
        ])->assertStatus(403);
    }

    /** §6.2: vet volante sem organização — só o próprio autor enxerga a internação. */
    public function test_vet_freelancer_without_an_organization_is_isolated(): void
    {
        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/professional/hospitalizations/{$this->hospitalization->id}")->assertStatus(403);
        $this->getJson('/api/professional/hospitalizations')
            ->assertOk()
            ->assertJsonMissing(['id' => $this->hospitalization->id]);
    }

    private function attachColleagueToAdmittingVetsOrganization(): User
    {
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->admittingVet->id,
        ]);

        $colleague = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $colleague->id,
        ]);

        return $colleague;
    }
}
