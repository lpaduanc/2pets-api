<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Organization\OrganizationMemberService;
use App\Services\Organization\UserRoleReconciler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Papel Spatie residual após perda de vínculo (dívida de segurança, 2026-09-12): antes desta
 * suíte, `assignRole` era sempre aditivo e nenhum ponto revogava — um `clinic_vet` desligado
 * da única clínica mantinha o papel para sempre. `UserRoleReconciler::reconcile()` recalcula
 * a família de papéis profissionais a partir das duas fontes legítimas (cadastro próprio +
 * vínculos ativos) sempre que um vínculo muda.
 */
class UserRoleReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private UserRoleReconciler $reconciler;

    private OrganizationMemberService $memberService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->reconciler = app(UserRoleReconciler::class);
        $this->memberService = app(OrganizationMemberService::class);
    }

    public function test_vet_loses_clinic_vet_role_after_losing_the_only_clinic_membership(): void
    {
        $organization = Organization::factory()->create();
        $vetUser = User::factory()->tutor()->create();
        $membership = OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $vetUser->id,
            'role' => OrganizationRole::VETERINARIAN,
        ]);
        $this->reconciler->reconcile($vetUser);
        $this->assertTrue($vetUser->fresh()->hasRole('clinic_vet'));

        $this->memberService->deactivate($membership);

        $this->assertFalse($vetUser->fresh()->hasRole('clinic_vet'));
    }

    public function test_vet_keeps_clinic_vet_role_when_another_active_clinic_membership_remains(): void
    {
        $clinicA = Organization::factory()->create();
        $clinicB = Organization::factory()->create();
        $vetUser = User::factory()->tutor()->create();
        $membershipA = OrganizationMember::factory()->for($clinicA, 'organization')->create([
            'user_id' => $vetUser->id,
            'role' => OrganizationRole::VETERINARIAN,
        ]);
        OrganizationMember::factory()->for($clinicB, 'organization')->create([
            'user_id' => $vetUser->id,
            'role' => OrganizationRole::VETERINARIAN,
        ]);
        $this->reconciler->reconcile($vetUser);

        $this->memberService->deactivate($membershipA);

        $this->assertTrue($vetUser->fresh()->hasRole('clinic_vet'));
    }

    public function test_freelance_vet_keeps_vet_freelancer_role_after_leaving_a_clinic(): void
    {
        $organization = Organization::factory()->create();
        $vetUser = User::factory()->veterinarian()->create();
        $membership = OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $vetUser->id,
            'role' => OrganizationRole::VETERINARIAN,
        ]);
        $this->reconciler->reconcile($vetUser);
        $this->assertTrue($vetUser->fresh()->hasRole('clinic_vet'));
        $this->assertTrue($vetUser->fresh()->hasRole('vet_freelancer'));

        $this->memberService->deactivate($membership);

        $vetUser->refresh();
        $this->assertFalse($vetUser->hasRole('clinic_vet'));
        $this->assertTrue($vetUser->hasRole('vet_freelancer'));
    }

    public function test_reconciliation_never_removes_the_admin_role(): void
    {
        $organization = Organization::factory()->create();
        $adminUser = User::factory()->admin()->create();
        OrganizationMember::factory()->owner()->for($organization, 'organization')->create([
            'user_id' => $adminUser->id,
        ]);

        $this->reconciler->reconcile($adminUser);

        $adminUser->refresh();
        $this->assertTrue($adminUser->hasRole('admin'));
        $this->assertTrue($adminUser->hasRole('clinic_owner'));
    }

    public function test_deactivating_and_reactivating_the_same_member_restores_the_role(): void
    {
        $organization = Organization::factory()->create();
        $vetUser = User::factory()->tutor()->create();
        $membership = OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $vetUser->id,
            'role' => OrganizationRole::VETERINARIAN,
        ]);
        $this->reconciler->reconcile($vetUser);

        $this->memberService->deactivate($membership);
        $this->assertFalse($vetUser->fresh()->hasRole('clinic_vet'));

        $this->memberService->updateMember($membership->fresh(), null, true);

        $this->assertTrue($vetUser->fresh()->hasRole('clinic_vet'));
    }
}
