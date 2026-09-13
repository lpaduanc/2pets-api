<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2 do split Pessoa/Organização — gestão de equipe. Só `owner` ativo gerencia; ninguém
 * gerencia organização da qual não é membro (IDOR); trocar cargo para `veterinarian` exige
 * CRMV (regra regulatória, `docs/rbac-clinica-autoria-e-staff.md`).
 */
class OrganizationMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_list_members(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $member = OrganizationMember::factory()->for($organization, 'organization')->create([
            'role' => OrganizationRole::ASSISTANT->value,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/organizations/{$organization->id}/members");

        $response->assertOk()->assertJsonFragment([
            'id' => $member->id,
            'role' => 'assistant',
            'role_label' => 'Auxiliar',
        ]);
    }

    public function test_non_member_cannot_list_members(): void
    {
        $organization = Organization::factory()->create();
        $outsider = User::factory()->tutor()->create();

        $response = $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/organizations/{$organization->id}/members");

        $response->assertForbidden();
    }

    public function test_a_member_who_is_not_owner_cannot_manage_members(): void
    {
        $organization = Organization::factory()->create();
        $this->createOwner($organization);
        $vetUser = User::factory()->tutor()->create();
        OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $vetUser->id,
            'role' => OrganizationRole::VETERINARIAN->value,
        ]);

        $response = $this->actingAs($vetUser, 'sanctum')
            ->getJson("/api/organizations/{$organization->id}/members");

        $response->assertForbidden();
    }

    public function test_owner_can_promote_member_to_veterinarian_when_user_has_crmv(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $userWithCrmv = User::factory()->tutor()->create();
        Professional::factory()->veterinarian()->create(['user_id' => $userWithCrmv->id, 'crmv' => '12345']);
        $member = OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $userWithCrmv->id,
            'role' => OrganizationRole::ASSISTANT->value,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/organizations/{$organization->id}/members/{$member->id}", [
                'role' => 'veterinarian',
            ]);

        $response->assertOk()->assertJsonFragment(['role' => 'veterinarian']);
        $this->assertDatabaseHas('organization_members', ['id' => $member->id, 'role' => 'veterinarian']);
        $this->assertTrue($userWithCrmv->fresh()->hasRole('clinic_vet'));
    }

    public function test_owner_cannot_promote_member_to_veterinarian_without_crmv(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $userWithoutCrmv = User::factory()->tutor()->create();
        $member = OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $userWithoutCrmv->id,
            'role' => OrganizationRole::ASSISTANT->value,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/organizations/{$organization->id}/members/{$member->id}", [
                'role' => 'veterinarian',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertDatabaseHas('organization_members', ['id' => $member->id, 'role' => 'assistant']);
        $this->assertFalse($userWithoutCrmv->fresh()->hasRole('clinic_vet'));
    }

    public function test_deactivating_a_member_sets_termination_date_and_never_deletes_the_row(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $member = OrganizationMember::factory()->for($organization, 'organization')->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/organizations/{$organization->id}/members/{$member->id}");

        $response->assertNoContent();
        $this->assertDatabaseHas('organization_members', [
            'id' => $member->id,
            'is_active' => false,
        ]);
        $this->assertNotNull($member->fresh()->termination_date);
    }

    public function test_reactivating_a_member_clears_termination_date(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $member = OrganizationMember::factory()->for($organization, 'organization')->create([
            'is_active' => false,
            'termination_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/organizations/{$organization->id}/members/{$member->id}", [
                'is_active' => true,
            ]);

        $response->assertOk();
        $this->assertNull($member->fresh()->termination_date);
        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_member_belonging_to_another_organization_returns_not_found(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $ownerA = $this->createOwner($organizationA);
        $memberOfB = OrganizationMember::factory()->for($organizationB, 'organization')->create();

        $response = $this->actingAs($ownerA, 'sanctum')
            ->patchJson("/api/organizations/{$organizationA->id}/members/{$memberOfB->id}", [
                'is_active' => false,
            ]);

        $response->assertNotFound();
    }

    public function test_deactivating_the_last_active_owner_is_blocked(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $ownerMember = OrganizationMember::where('organization_id', $organization->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/organizations/{$organization->id}/members/{$ownerMember->id}");

        $response->assertStatus(422);
        $this->assertTrue($ownerMember->fresh()->is_active);
    }

    public function test_demoting_the_last_active_owner_is_blocked(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $ownerMember = OrganizationMember::where('organization_id', $organization->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/organizations/{$organization->id}/members/{$ownerMember->id}", [
                'role' => 'assistant',
            ]);

        $response->assertStatus(422);
        $this->assertSame(OrganizationRole::OWNER, $ownerMember->fresh()->role);
    }

    public function test_deactivating_an_owner_is_allowed_when_another_active_owner_remains(): void
    {
        $organization = Organization::factory()->create();
        $firstOwner = $this->createOwner($organization);
        $secondOwnerMember = OrganizationMember::factory()->owner()->for($organization, 'organization')->create();
        $firstOwnerMember = OrganizationMember::where('organization_id', $organization->id)
            ->where('user_id', $firstOwner->id)
            ->firstOrFail();

        $response = $this->actingAs($firstOwner, 'sanctum')
            ->deleteJson("/api/organizations/{$organization->id}/members/{$firstOwnerMember->id}");

        $response->assertNoContent();
        $this->assertFalse($firstOwnerMember->fresh()->is_active);
        $this->assertTrue($secondOwnerMember->fresh()->is_active);
    }

    private function createOwner(Organization $organization): User
    {
        $owner = User::factory()->tutor()->create();

        OrganizationMember::factory()->owner()->for($organization, 'organization')->create([
            'user_id' => $owner->id,
        ]);

        return $owner;
    }
}
