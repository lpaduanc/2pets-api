<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Override de permissão por membro sem enforcement (revisão de segurança, achado Médio 4) —
 * `OrganizationMember::hasPermission()` era gravado por
 * `PUT organizations/{organization}/members/{member}/permissions` e exibido na UI, mas
 * `EnsurePermission` e `PermissionSummaryService` (`GET me/permissions`) nunca o
 * consultavam. Rota de teste isolada, mesmo padrão de `EnsurePermissionMiddlewareTest`.
 */
class OrganizationPermissionOverrideEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Route::middleware(['api', 'auth:sanctum', 'permission:financial-entries.view.own|financial-entries.view.any'])
            ->get('/_test/permission-gate', fn () => response()->json(['ok' => true]));

        Route::middleware(['api', 'auth:sanctum', 'permission:medical-records.create'])
            ->get('/_test/clinical-gate', fn () => response()->json(['ok' => true]));
    }

    public function test_member_without_the_role_permission_passes_the_gate_via_organization_override(): void
    {
        $receptionist = User::factory()->tutor()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $receptionist->id,
            'role' => OrganizationRole::RECEPTIONIST->value,
            'permissions' => ['financial-entries.view.any'],
            'is_active' => true,
        ]);

        $this->actingAs($receptionist)
            ->getJson('/_test/permission-gate')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_get_me_permissions_reflects_the_same_organization_override(): void
    {
        $receptionist = User::factory()->tutor()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $receptionist->id,
            'role' => OrganizationRole::RECEPTIONIST->value,
            'permissions' => ['financial-entries.view.any'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($receptionist)->getJson('/api/me/permissions');

        $response->assertOk();
        $this->assertContains('financial-entries.view.any', $response->json('permissions'));
    }

    public function test_override_never_grants_a_clinical_act_to_a_non_clinical_role(): void
    {
        $receptionist = User::factory()->tutor()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $receptionist->id,
            'role' => OrganizationRole::RECEPTIONIST->value,
            'permissions' => ['medical-records.create'],
            'is_active' => true,
        ]);

        $this->actingAs($receptionist)
            ->getJson('/_test/clinical-gate')
            ->assertForbidden();

        $response = $this->actingAs($receptionist)->getJson('/api/me/permissions');
        $this->assertNotContains('medical-records.create', $response->json('permissions'));
    }

    public function test_an_inactive_membership_never_grants_its_override(): void
    {
        $former = User::factory()->tutor()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $former->id,
            'role' => OrganizationRole::RECEPTIONIST->value,
            'permissions' => ['financial-entries.view.any'],
            'is_active' => false,
        ]);

        $this->actingAs($former)
            ->getJson('/_test/permission-gate')
            ->assertForbidden();
    }

    public function test_user_without_any_membership_or_role_permission_still_gets_403(): void
    {
        $tutor = User::factory()->tutor()->create();

        $this->actingAs($tutor)
            ->getJson('/_test/permission-gate')
            ->assertForbidden();
    }
}
