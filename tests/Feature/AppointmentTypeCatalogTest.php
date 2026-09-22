<?php

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `appointment-types` (cadastro de agenda) — contrato docs/gap-simplesvet/specs/
 * 14-tipos-atendimento-modelos-prontuario-spec.md. Reaproveita o CRUD genérico do item 23
 * (`catalogs/{type}`) — este teste cobre só o que é próprio deste documento: `category`
 * obrigatória e restrita a `ServiceCategory`, e que o cadastro nunca bloqueia agendamento.
 */
class AppointmentTypeCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function ownerFor(Organization $organization): User
    {
        $owner = User::factory()->tutor()->create();
        $owner->assignRole(Role::findOrCreate('clinic_owner', 'web'));
        OrganizationMember::factory()->owner()->for($organization)->for($owner, 'user')->create();

        return $owner;
    }

    public function test_store_rejects_category_outside_service_category(): void
    {
        $owner = $this->ownerFor(Organization::factory()->create());

        $response = $this->actingAs($owner)->postJson('/api/catalogs/appointment-types', [
            'name' => 'Avaliação Odontológica',
            'category' => 'not-a-real-category',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_store_creates_appointment_type_with_agenda_metadata(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->ownerFor($organization);

        $response = $this->actingAs($owner)->postJson('/api/catalogs/appointment-types', [
            'name' => 'Avaliação Odontológica',
            'category' => 'dental',
            'default_duration_minutes' => 40,
            'color' => '#7C3AED',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', 'dental');
        $response->assertJsonPath('data.default_duration_minutes', 40);
        $this->assertDatabaseHas('appointment_types', [
            'organization_id' => $organization->id,
            'name' => 'Avaliação Odontológica',
            'category' => 'dental',
        ]);
    }

    public function test_index_filters_by_category(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->ownerFor($organization);

        AppointmentType::create([
            'organization_id' => $organization->id,
            'name' => 'Avaliação Odontológica',
            'category' => 'dental',
        ]);
        AppointmentType::create([
            'organization_id' => $organization->id,
            'name' => 'Consulta Dermatológica',
            'category' => 'consultation',
        ]);

        $response = $this->actingAs($owner)->getJson('/api/catalogs/appointment-types?category=dental');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Avaliação Odontológica', $names);
        $this->assertNotContains('Consulta Dermatológica', $names);
    }

    public function test_user_without_catalog_manage_cannot_create_appointment_type(): void
    {
        $organization = Organization::factory()->create();
        $vet = User::factory()->tutor()->create();
        $vet->assignRole(Role::findOrCreate('clinic_vet', 'web'));
        OrganizationMember::factory()->for($organization)->for($vet, 'user')->create();

        $this->actingAs($vet)
            ->postJson('/api/catalogs/appointment-types', ['name' => 'Retorno', 'category' => 'consultation'])
            ->assertForbidden();
    }
}
