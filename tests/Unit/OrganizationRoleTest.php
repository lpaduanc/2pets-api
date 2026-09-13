<?php

namespace Tests\Unit;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mapa cargo→papel Spatie de `docs/rbac-clinica-autoria-e-staff.md` §6-7 — fonte de verdade
 * de permissão da Fase 2. Puro enum, sem banco: roda como Unit, não Feature.
 */
class OrganizationRoleTest extends TestCase
{
    public static function ownerMappings(): array
    {
        return [
            'clinic' => [OrganizationType::CLINIC, 'clinic_owner'],
            'laboratory' => [OrganizationType::LABORATORY, 'clinic_owner'],
            'petshop' => [OrganizationType::PETSHOP, 'petshop_owner'],
            'pet_hotel' => [OrganizationType::PET_HOTEL, 'petshop_owner'],
            'grooming' => [OrganizationType::GROOMING, 'petshop_owner'],
            'training' => [OrganizationType::TRAINING, 'petshop_owner'],
        ];
    }

    #[DataProvider('ownerMappings')]
    public function test_owner_spatie_role_depends_on_organization_type(OrganizationType $type, string $expectedSpatieRole): void
    {
        $this->assertSame($expectedSpatieRole, OrganizationRole::OWNER->spatieRole($type));
    }

    public function test_veterinarian_always_maps_to_clinic_vet(): void
    {
        foreach (OrganizationType::cases() as $type) {
            $this->assertSame('clinic_vet', OrganizationRole::VETERINARIAN->spatieRole($type));
        }
    }

    public static function nonClinicalStaffRoles(): array
    {
        return [
            'assistant' => [OrganizationRole::ASSISTANT],
            'receptionist' => [OrganizationRole::RECEPTIONIST],
            'groomer' => [OrganizationRole::GROOMER],
            'technician' => [OrganizationRole::TECHNICIAN],
        ];
    }

    #[DataProvider('nonClinicalStaffRoles')]
    public function test_non_clinical_staff_always_maps_to_petshop_staff(OrganizationRole $role): void
    {
        foreach (OrganizationType::cases() as $type) {
            $this->assertSame('petshop_staff', $role->spatieRole($type));
        }
    }

    public function test_only_veterinarian_is_clinical(): void
    {
        $this->assertTrue(OrganizationRole::VETERINARIAN->isClinical());

        foreach (OrganizationRole::cases() as $role) {
            if ($role === OrganizationRole::VETERINARIAN) {
                continue;
            }

            $this->assertFalse($role->isClinical(), "{$role->value} não deveria ser clínico.");
        }
    }

    public function test_values_match_the_six_roles_allowed_in_the_database_check_constraint(): void
    {
        $this->assertSame(
            ['owner', 'veterinarian', 'assistant', 'receptionist', 'groomer', 'technician'],
            OrganizationRole::values()
        );
    }
}
