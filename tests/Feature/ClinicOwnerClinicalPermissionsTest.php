<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ato clínico é privativo de médico-veterinário PESSOA FÍSICA com CRMV ativo — Lei 5.517/1968
 * art. 1º, Res. CFMV 1.318/2020 (prescrição) e Res. CFMV 1.321/2020 alterada pela 1.653/2025
 * (cada evolução do prontuário carrega nome e CRMV do autor).
 *
 * `clinic_owner` é papel administrativo: a conta que cadastra a clínica pode não ter CRMV
 * nenhum. O seeder concedia a ela `medical-records.create`, `prescriptions.create` e mais seis —
 * ou seja, o RBAC autorizava um CNPJ a assinar prontuário e receita, contradizendo a própria
 * `PetPolicy`, que nega acesso a pet para quem não é `isVeterinarian()`.
 *
 * Decisão completa em `docs/rbac-clinica-autoria-e-staff.md`.
 */
class ClinicOwnerClinicalPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Atos privativos de veterinário. Nenhum papel puramente administrativo pode tê-los.
     *
     * @return list<string>
     */
    private const CLINICAL_ACT_PERMISSIONS = [
        'medical-records.create',
        'prescriptions.create',
        'vaccinations.create',
        'exams.create',
        'hospitalizations.create',
        'hospitalizations.discharge',
        'surgeries.create',
        'surgeries.cancel',
    ];

    /**
     * Editar prontuário é ato clínico tanto quanto criar (Res. CFMV 1.321/2020 alterada pela
     * 1.653/2025: nome e CRMV do responsável em CADA evolução). Apagar é pior ainda — prontuário
     * tem guarda obrigatória. Nenhum papel administrativo pode nada disso.
     *
     * @return list<string>
     */
    private const CLINICAL_MUTATION_PERMISSIONS = [
        'medical-records.update.any',
        'medical-records.delete',
        'prescriptions.update',
        'prescriptions.delete',
        'vaccinations.update',
        'vaccinations.delete',
        'exams.update',
        'exams.delete',
        'hospitalizations.update',
        'surgeries.update',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_clinic_owner_cannot_perform_clinical_acts(): void
    {
        $clinicOwner = Role::findByName('clinic_owner', 'web');

        foreach (self::CLINICAL_ACT_PERMISSIONS as $permission) {
            $this->assertFalse(
                $clinicOwner->hasPermissionTo($permission),
                "`clinic_owner` não pode ter `{$permission}`: dono de clínica pode não ter CRMV."
            );
        }
    }

    public function test_clinic_owner_cannot_edit_or_delete_clinical_records(): void
    {
        $clinicOwner = Role::findByName('clinic_owner', 'web');

        foreach (self::CLINICAL_MUTATION_PERMISSIONS as $permission) {
            $this->assertFalse(
                $clinicOwner->hasPermissionTo($permission),
                "`clinic_owner` não pode ter `{$permission}`: editar e apagar dado clínico também é ato privativo."
            );
        }
    }

    /**
     * Operação destrutiva sobre dado clínico não é rotina de ninguém: prontuário, receita,
     * vacina e exame têm guarda obrigatória. Só `super_admin` — e via suporte, não pela tela.
     */
    public function test_destructive_clinical_permissions_belong_to_super_admin_only(): void
    {
        $destructive = [
            'medical-records.update.any',
            'medical-records.delete',
            'prescriptions.delete',
            'vaccinations.delete',
            'exams.delete',
        ];

        $rolesThatMayNotHaveThem = ['clinic_owner', 'clinic_vet', 'vet_freelancer', 'petshop_owner', 'petshop_staff', 'tutor'];

        foreach ($destructive as $permission) {
            $this->assertTrue(
                Role::findByName('super_admin', 'web')->hasPermissionTo($permission),
                "`super_admin` deveria manter `{$permission}` para atendimento de suporte."
            );

            foreach ($rolesThatMayNotHaveThem as $roleName) {
                $this->assertFalse(
                    Role::findByName($roleName, 'web')->hasPermissionTo($permission),
                    "`{$roleName}` não pode ter `{$permission}`."
                );
            }
        }
    }

    public function test_petshop_owner_cannot_perform_clinical_acts(): void
    {
        $petshopOwner = Role::findByName('petshop_owner', 'web');

        foreach (self::CLINICAL_ACT_PERMISSIONS as $permission) {
            $this->assertFalse(
                $petshopOwner->hasPermissionTo($permission),
                "`petshop_owner` não pode ter `{$permission}`: petshop não pratica ato clínico."
            );
        }
    }

    /**
     * Contraprova: se o seeder deixasse de conceder as permissões clínicas a QUALQUER papel, o
     * teste acima passaria por vacuidade. O ato clínico tem que continuar existindo para quem
     * tem CRMV.
     */
    public function test_veterinarian_roles_keep_clinical_acts(): void
    {
        foreach (['vet_freelancer', 'clinic_vet'] as $roleName) {
            $role = Role::findByName($roleName, 'web');

            foreach (self::CLINICAL_ACT_PERMISSIONS as $permission) {
                $this->assertTrue(
                    $role->hasPermissionTo($permission),
                    "`{$roleName}` precisa manter `{$permission}` — é quem tem CRMV."
                );
            }
        }
    }

    /**
     * A correção tira do dono o ato clínico, não a gestão do negócio. Se estas sumirem, o painel
     * da clínica fica inútil e a regressão passa despercebida.
     */
    public function test_clinic_owner_keeps_operational_permissions(): void
    {
        $clinicOwner = Role::findByName('clinic_owner', 'web');

        $operational = [
            'appointments.view.any',
            'appointments.create',
            'medical-records.view.any',
            'prescriptions.view.any',
            'invoices.create',
            'inventory.view',
            'staff.create',
            'reports.view.any',
        ];

        foreach ($operational as $permission) {
            $this->assertTrue(
                $clinicOwner->hasPermissionTo($permission),
                "`clinic_owner` perdeu `{$permission}`, que é gestão do negócio e deve permanecer."
            );
        }
    }
}
