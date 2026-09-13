<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `organization_members.permissions` (JSON) é exceção pontual, nunca fonte de ato clínico —
 * regra regulatória do `docs/rbac-clinica-autoria-e-staff.md` §6-7, aplicada em
 * `OrganizationMember::hasPermission()`. Cobre exatamente o pedido da Fase 2: "ela NUNCA pode
 * conceder permissão clínica a cargo não-clínico".
 */
class OrganizationMemberPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_permissions_cannot_grant_a_clinical_act_to_a_non_clinical_role(): void
    {
        $receptionist = OrganizationMember::factory()->create([
            'role' => OrganizationRole::RECEPTIONIST->value,
            'permissions' => ['medical-records.create'],
        ]);

        $this->assertFalse($receptionist->hasPermission('medical-records.create'));
    }

    public function test_json_permissions_still_grant_non_clinical_acts(): void
    {
        $receptionist = OrganizationMember::factory()->create([
            'role' => OrganizationRole::RECEPTIONIST->value,
            'permissions' => ['appointments.view.any'],
        ]);

        $this->assertTrue($receptionist->hasPermission('appointments.view.any'));
    }

    public function test_veterinarian_role_can_be_granted_a_clinical_act_via_permissions(): void
    {
        $veterinarian = OrganizationMember::factory()->create([
            'role' => OrganizationRole::VETERINARIAN->value,
            'permissions' => ['medical-records.create'],
        ]);

        $this->assertTrue($veterinarian->hasPermission('medical-records.create'));
    }

    public function test_no_clinical_act_leaks_through_json_for_any_non_clinical_role(): void
    {
        $nonClinicalRoles = [
            OrganizationRole::OWNER,
            OrganizationRole::ASSISTANT,
            OrganizationRole::RECEPTIONIST,
            OrganizationRole::GROOMER,
            OrganizationRole::TECHNICIAN,
        ];

        $clinicalActs = [
            'medical-records.create',
            'prescriptions.create',
            'vaccinations.create',
            'exams.create',
            'hospitalizations.create',
            'hospitalizations.discharge',
            'surgeries.create',
            'surgeries.cancel',
        ];

        foreach ($nonClinicalRoles as $role) {
            $member = OrganizationMember::factory()->create([
                'role' => $role->value,
                'permissions' => $clinicalActs,
            ]);

            foreach ($clinicalActs as $act) {
                $this->assertFalse(
                    $member->hasPermission($act),
                    "{$role->value} não pode ganhar `{$act}` via permissions."
                );
            }
        }
    }
}
