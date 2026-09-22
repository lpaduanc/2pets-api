<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `GET me/permissions` — item 22 do backlog gap-simplesvet. O app monta menu/UI a partir
 * disto; um item sem permissão não pode aparecer na resposta.
 */
class MePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_tutor_sees_own_permissions_and_role(): void
    {
        $tutor = User::factory()->tutor()->create();

        $response = $this->actingAs($tutor)->getJson('/api/me/permissions');

        $response->assertOk();
        $response->assertJsonPath('roles.0', 'tutor');
        $this->assertContains('pets.view.own', $response->json('permissions'));
        $this->assertContains('client-account.view.own', $response->json('permissions'));
        $this->assertNotContains('financial-entries.manage', $response->json('permissions'));
    }

    public function test_tutor_never_sees_clinical_act_permissions(): void
    {
        $tutor = User::factory()->tutor()->create();

        $response = $this->actingAs($tutor)->getJson('/api/me/permissions');

        $this->assertNotContains('medical-records.create', $response->json('permissions'));
        $this->assertNotContains('prescriptions.create', $response->json('permissions'));
    }

    public function test_organization_owner_sees_active_organization_role(): void
    {
        $owner = User::factory()->tutor()->create();
        $owner->assignRole(Role::findOrCreate('clinic_owner', 'web'));

        $organization = Organization::factory()->create();
        OrganizationMember::factory()->owner()->for($organization)->for($owner, 'user')->create();

        $response = $this->actingAs($owner)->getJson('/api/me/permissions');

        $response->assertOk();
        $this->assertContains('financial-entries.view.any', $response->json('permissions'));
        $this->assertContains('catalog.manage', $response->json('permissions'));
        $response->assertJsonFragment(['organization_id' => $organization->id, 'role' => 'owner']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/me/permissions')->assertUnauthorized();
    }
}
