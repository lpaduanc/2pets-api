<?php

namespace Tests\Unit;

use App\Enums\ProfessionalType;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalDifferential;
use App\Enums\Registration\ProfessionalFacility;
use App\Enums\Registration\RequirementLevel;
use App\Enums\ServiceCategory;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A matriz `ProfessionalType × capacidades` (`docs/segmentacao-cadastro-profissional.md`
 * §2-4) como dado puro — sem banco, roda como Unit. Cobre os critérios de aceite do
 * documento: "cadastro de X não vê/aceita serviço de categoria Y" para os 7 tipos MVP.
 */
class ProfessionalCapabilityRegistryTest extends TestCase
{
    #[DataProvider('allowedAndForbiddenServiceCategoryProvider')]
    public function test_service_category_permission_matches_the_documented_matrix(
        ProfessionalType $type,
        ServiceCategory $allowed,
        ServiceCategory $forbidden,
    ): void {
        $capabilities = ProfessionalCapabilityRegistry::for($type);

        $this->assertTrue($capabilities->allowsServiceCategory($allowed));
        $this->assertFalse($capabilities->allowsServiceCategory($forbidden));
    }

    /** @return array<string, array{ProfessionalType, ServiceCategory, ServiceCategory}> */
    public static function allowedAndForbiddenServiceCategoryProvider(): array
    {
        return [
            'vet: consulta sim, cirurgia não (sem estrutura)' => [ProfessionalType::VET, ServiceCategory::CONSULTATION, ServiceCategory::SURGERY],
            'clinic: cirurgia sim, adestramento não' => [ProfessionalType::CLINIC, ServiceCategory::SURGERY, ServiceCategory::TRAINING],
            'laboratory: laboratorial sim, consulta não (não é ato clínico do laboratório)' => [ProfessionalType::LABORATORY, ServiceCategory::LABORATORY, ServiceCategory::CONSULTATION],
            'petshop: banho e tosa sim, consulta não' => [ProfessionalType::PETSHOP, ServiceCategory::GROOMING, ServiceCategory::CONSULTATION],
            'pet_hotel: hospedagem sim, cirurgia não' => [ProfessionalType::PET_HOTEL, ServiceCategory::BOARDING, ServiceCategory::SURGERY],
            'grooming: banho e tosa sim, hospedagem não' => [ProfessionalType::GROOMING, ServiceCategory::GROOMING, ServiceCategory::BOARDING],
            'training: adestramento sim, banho e tosa não' => [ProfessionalType::TRAINING, ServiceCategory::TRAINING, ServiceCategory::GROOMING],
        ];
    }

    /**
     * A queixa literal do dono do produto: "se não tem clínica, não tem sala, não tem
     * estacionamento" — `vet` nunca deve ganhar nenhum item de estrutura física.
     */
    public function test_vet_never_allows_any_physical_facility(): void
    {
        $capabilities = ProfessionalCapabilityRegistry::for(ProfessionalType::VET);

        $this->assertFalse($capabilities->hasPhysicalAddress);
        $this->assertSame([], $capabilities->facilities);
        $this->assertFalse($capabilities->allowsFacility(ProfessionalFacility::PARKING_AVAILABLE));
        $this->assertFalse($capabilities->allowsFacility(ProfessionalFacility::WHEELCHAIR_ACCESSIBLE));
    }

    /**
     * `vet` ganhou equipamento portátil em 2026-09-13
     * (`docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.1) — corrigiu a premissa de que
     * "sem prédio" era sinônimo de "sem equipamento". `clinic`/`laboratory` continuam com o
     * catálogo inteiro; só os tipos sem CRMV/RT (petshop, pet_hotel, grooming, training)
     * seguem sem nenhum item clínico.
     */
    public function test_only_vet_clinic_and_laboratory_get_clinical_equipment(): void
    {
        $withEquipment = [ProfessionalType::VET, ProfessionalType::CLINIC, ProfessionalType::LABORATORY];

        foreach (ProfessionalType::cases() as $type) {
            $capabilities = ProfessionalCapabilityRegistry::for($type);
            $expectsEquipment = in_array($type, $withEquipment, true);

            $this->assertSame($expectsEquipment, $capabilities->equipment !== [], "professional_type={$type->value}");
        }
    }

    /**
     * `vet` recebe só o subconjunto portátil (7 itens) — nunca os itens de estrutura fixa
     * (`SURGERY_ROOM`, `ICU`, `KENNELS`, `AMBULANCE`, `PHARMACY`), que dependem de prédio.
     */
    public function test_vet_equipment_is_the_portable_subset_not_the_full_catalog(): void
    {
        $vetEquipment = ProfessionalCapabilityRegistry::for(ProfessionalType::VET)->equipment;

        $this->assertContains(ClinicalEquipment::ULTRASOUND_MACHINE, $vetEquipment);
        $this->assertContains(ClinicalEquipment::XRAY_MACHINE, $vetEquipment);
        $this->assertNotContains(ClinicalEquipment::SURGERY_ROOM, $vetEquipment);
        $this->assertNotContains(ClinicalEquipment::ICU, $vetEquipment);
        $this->assertNotContains(ClinicalEquipment::AMBULANCE, $vetEquipment);
        $this->assertCount(7, $vetEquipment);
    }

    /**
     * Cirurgia e internação continuam fora do `vet` mesmo com equipamento portátil (§2.3) —
     * `imaging`/`laboratory` é que passam a ser permitidos.
     */
    public function test_vet_now_allows_imaging_and_laboratory_but_not_surgery_or_hospitalization(): void
    {
        $capabilities = ProfessionalCapabilityRegistry::for(ProfessionalType::VET);

        $this->assertTrue($capabilities->allowsServiceCategory(ServiceCategory::IMAGING));
        $this->assertTrue($capabilities->allowsServiceCategory(ServiceCategory::LABORATORY));
        $this->assertFalse($capabilities->allowsServiceCategory(ServiceCategory::SURGERY));
        $this->assertFalse($capabilities->allowsServiceCategory(ServiceCategory::HOSPITALIZATION));
    }

    public function test_only_clinic_and_laboratory_require_technical_responsible(): void
    {
        $this->assertTrue(ProfessionalCapabilityRegistry::for(ProfessionalType::CLINIC)->requiresTechnicalResponsible);
        $this->assertTrue(ProfessionalCapabilityRegistry::for(ProfessionalType::LABORATORY)->requiresTechnicalResponsible);

        foreach ([ProfessionalType::VET, ProfessionalType::PETSHOP, ProfessionalType::PET_HOTEL, ProfessionalType::GROOMING, ProfessionalType::TRAINING] as $type) {
            $this->assertFalse(ProfessionalCapabilityRegistry::for($type)->requiresTechnicalResponsible, "professional_type={$type->value}");
        }
    }

    /** Raio de atendimento (docs §6): obrigatório só para vet, opcional para móvel, ausente para os demais. */
    public function test_service_radius_requirement_by_type(): void
    {
        $capabilities = fn (ProfessionalType $type) => ProfessionalCapabilityRegistry::for($type);

        $this->assertSame(RequirementLevel::REQUIRED, $capabilities(ProfessionalType::VET)->serviceRadius);
        $this->assertSame(RequirementLevel::OPTIONAL, $capabilities(ProfessionalType::GROOMING)->serviceRadius);
        $this->assertSame(RequirementLevel::OPTIONAL, $capabilities(ProfessionalType::TRAINING)->serviceRadius);
        $this->assertNull($capabilities(ProfessionalType::CLINIC)->serviceRadius);
        $this->assertNull($capabilities(ProfessionalType::PETSHOP)->serviceRadius);
    }

    public function test_accepts_credit_card_and_pet_insurance_are_common_to_every_type(): void
    {
        foreach (ProfessionalCapabilityRegistry::all() as $capabilities) {
            $this->assertTrue(
                $capabilities->allowsDifferential(ProfessionalDifferential::ACCEPTS_CREDIT_CARD),
                "professional_type={$capabilities->type->value}",
            );
            $this->assertTrue(
                $capabilities->allowsDifferential(ProfessionalDifferential::ACCEPTS_PET_INSURANCE),
                "professional_type={$capabilities->type->value}",
            );
        }
    }

    public function test_pet_hotel_and_grooming_require_sizes_served_others_do_not(): void
    {
        $this->assertSame(RequirementLevel::REQUIRED, ProfessionalCapabilityRegistry::for(ProfessionalType::PET_HOTEL)->sizesServed);
        $this->assertSame(RequirementLevel::REQUIRED, ProfessionalCapabilityRegistry::for(ProfessionalType::GROOMING)->sizesServed);
        $this->assertNull(ProfessionalCapabilityRegistry::for(ProfessionalType::LABORATORY)->sizesServed);
    }
}
