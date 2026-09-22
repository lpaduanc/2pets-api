<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 22 do backlog gap-simplesvet — "Nota de coordenação" do contrato de API: reconciliar
 * automaticamente o papel Spatie a partir do `OrganizationRole` do membro, para que uma
 * fixture que cria `OrganizationMember` direto (sem passar pelo fluxo de convite) não fique
 * sem o papel que uma rota `permission:...` exige.
 */
class OrganizationMemberRoleReconciliationObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_member_directly_grants_the_matching_spatie_role_when_the_catalog_is_seeded(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $user = User::factory()->tutor()->create();

        OrganizationMember::factory()->owner()->for($organization, 'organization')->create(['user_id' => $user->id]);

        $this->assertTrue($user->fresh()->hasRole('clinic_owner'));
    }

    public function test_changing_the_role_reconciles_the_spatie_role_without_a_manual_call(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $user = User::factory()->tutor()->create();
        $member = OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $user->id,
            'role' => OrganizationRole::ASSISTANT->value,
        ]);
        $this->assertTrue($user->fresh()->hasRole('petshop_staff'));

        $member->update(['role' => OrganizationRole::VETERINARIAN->value]);

        $this->assertTrue($user->fresh()->hasRole('clinic_vet'));
        $this->assertFalse($user->fresh()->hasRole('petshop_staff'));
    }

    /**
     * Sem seed, o papel esperado ainda não existe em `roles` — reconciliar tem que ser um
     * no-op silencioso (`UserRoleReconciler::onlyRolesRegisteredInCatalog()`), nunca uma
     * `RoleDoesNotExist` estourando na criação de uma fixture comum que não seedou o
     * catálogo de propósito.
     */
    public function test_creating_a_member_without_seeding_the_catalog_does_not_throw(): void
    {
        $organization = Organization::factory()->create();

        $member = OrganizationMember::factory()->owner()->for($organization, 'organization')->create();

        $this->assertNotNull($member->id);
        $this->assertSame([], $member->user->fresh()->getRoleNames()->all());
    }
}
