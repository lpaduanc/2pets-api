<?php

namespace App\Support\Registration;

use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalAdditionalField;
use App\Enums\Registration\ProfessionalDifferential;
use App\Enums\Registration\ProfessionalFacility;
use App\Enums\Registration\RequirementLevel;
use App\Enums\ServiceCategory;

/**
 * A matriz `ProfessionalType × capacidades` como DADO puro
 * (`docs/segmentacao-cadastro-profissional.md` §2-4) — sem lógica além de montar o array.
 * Extraída de `ProfessionalCapabilityRegistry` só para não estourar o limite de linhas de
 * classe nova: adicionar um tipo novo é adicionar uma entrada aqui.
 */
final class ProfessionalCapabilityDefinitions
{
    /** @return list<string> */
    public static function businessSteps(bool $requiresTechnicalResponsible): array
    {
        return $requiresTechnicalResponsible
            ? ['identification', 'technical_responsible', 'location', 'species', 'services', 'structure', 'documents']
            : ['identification', 'location', 'species', 'services', 'structure', 'documents'];
    }

    /** @return list<string> */
    public static function businessDocuments(bool $requiresTechnicalResponsible): array
    {
        return $requiresTechnicalResponsible
            ? ['cnpj_card', 'business_license', 'technical_responsible_crmv']
            : ['cnpj_card', 'business_license'];
    }

    /**
     * @return array<string, array{
     *     identification: 'cpf'|'cnpj',
     *     requires_crmv: bool,
     *     requires_technical_responsible: bool,
     *     has_physical_address: bool,
     *     service_radius: ?RequirementLevel,
     *     service_categories: list<ServiceCategory>,
     *     equipment: list<ClinicalEquipment>,
     *     facilities: list<ProfessionalFacility>,
     *     differentials: list<ProfessionalDifferential>,
     *     species_served: ?RequirementLevel,
     *     sizes_served: ?RequirementLevel,
     *     additional_fields: list<ProfessionalAdditionalField>,
     *     documents: list<string>,
     *     steps: list<string>,
     * }>
     */
    public static function all(): array
    {
        $allEquipment = ClinicalEquipment::cases();
        $bothFacilities = [ProfessionalFacility::PARKING_AVAILABLE, ProfessionalFacility::WHEELCHAIR_ACCESSIBLE];
        $common = [ProfessionalDifferential::ACCEPTS_CREDIT_CARD, ProfessionalDifferential::ACCEPTS_PET_INSURANCE];
        $languages = [ProfessionalAdditionalField::LANGUAGES_SPOKEN];

        return [
            'vet' => [
                'identification' => 'cpf', 'requires_crmv' => true, 'requires_technical_responsible' => false,
                'has_physical_address' => false, 'service_radius' => RequirementLevel::REQUIRED,
                // `imaging`/`laboratory` entraram em 2026-09-13 (correção de
                // `docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.2 — equipamento
                // portátil é prática de mercado real, não "vet encaminha, não presta").
                // Cirurgia e internação continuam fora: dependem de ambiente controlado, não
                // de aparelho (§2.3) — equipamento portátil não muda essa conclusão.
                'service_categories' => [
                    ServiceCategory::CONSULTATION, ServiceCategory::VACCINATION, ServiceCategory::EMERGENCY,
                    ServiceCategory::NUTRITION, ServiceCategory::BEHAVIORAL, ServiceCategory::REHABILITATION,
                    ServiceCategory::IMAGING, ServiceCategory::LABORATORY,
                ],
                // Subconjunto portátil do catálogo (§1.2/§2.1) — nunca os itens de estrutura
                // fixa (`SURGERY_ROOM`, `ICU`, `KENNELS`, `AMBULANCE`, `PHARMACY`), que
                // dependem de prédio e continuam fora do `vet`. `HasProfessionalCapabilityRules`
                // + `ServiceEquipmentDependency` garantem que `imaging`/`laboratory` só podem
                // ser oferecidos se pelo menos um destes estiver declarado.
                'equipment' => [
                    ClinicalEquipment::XRAY_MACHINE,
                    ClinicalEquipment::ULTRASOUND_MACHINE,
                    ClinicalEquipment::ECG_MACHINE,
                    ClinicalEquipment::PORTABLE_MULTIPARAMETER_MONITOR,
                    ClinicalEquipment::PORTABLE_OXYGEN_THERAPY,
                    ClinicalEquipment::VASCULAR_DOPPLER,
                    ClinicalEquipment::POINT_OF_CARE_ANALYZER,
                ],
                'facilities' => [],
                'differentials' => [
                    ...$common, ProfessionalDifferential::HOME_VISIT_AVAILABLE,
                    ProfessionalDifferential::ONLINE_CONSULTATION, ProfessionalDifferential::EMERGENCY_AVAILABLE,
                ],
                'species_served' => RequirementLevel::REQUIRED, 'sizes_served' => RequirementLevel::OPTIONAL,
                'additional_fields' => $languages,
                'documents' => ['crmv', 'diploma'], 'steps' => ['identification', 'credentials', 'services', 'documents'],
            ],
            'clinic' => [
                'identification' => 'cnpj', 'requires_crmv' => false, 'requires_technical_responsible' => true,
                'has_physical_address' => true, 'service_radius' => null,
                'service_categories' => [
                    ServiceCategory::CONSULTATION, ServiceCategory::VACCINATION, ServiceCategory::SURGERY,
                    ServiceCategory::HOSPITALIZATION, ServiceCategory::IMAGING, ServiceCategory::LABORATORY,
                    ServiceCategory::DENTAL, ServiceCategory::NUTRITION, ServiceCategory::BEHAVIORAL,
                    ServiceCategory::REHABILITATION, ServiceCategory::EMERGENCY, ServiceCategory::GROOMING,
                ],
                'equipment' => $allEquipment, 'facilities' => $bothFacilities,
                'differentials' => [
                    ...$common, ProfessionalDifferential::HOME_VISIT_AVAILABLE,
                    ProfessionalDifferential::ONLINE_CONSULTATION, ProfessionalDifferential::EMERGENCY_AVAILABLE,
                    ProfessionalDifferential::EMERGENCY_24H,
                ],
                'species_served' => RequirementLevel::REQUIRED, 'sizes_served' => RequirementLevel::OPTIONAL,
                'additional_fields' => [ProfessionalAdditionalField::EXAM_ROOMS_COUNT, ...$languages],
                'documents' => self::businessDocuments(true), 'steps' => self::businessSteps(true),
            ],
            'laboratory' => [
                'identification' => 'cnpj', 'requires_crmv' => false, 'requires_technical_responsible' => true,
                'has_physical_address' => true, 'service_radius' => null,
                'service_categories' => [ServiceCategory::IMAGING, ServiceCategory::LABORATORY],
                'equipment' => $allEquipment, 'facilities' => $bothFacilities, 'differentials' => $common,
                'species_served' => RequirementLevel::OPTIONAL, 'sizes_served' => null,
                'additional_fields' => [ProfessionalAdditionalField::EXAM_ROOMS_COUNT, ...$languages],
                'documents' => self::businessDocuments(true), 'steps' => self::businessSteps(true),
            ],
            'petshop' => [
                'identification' => 'cnpj', 'requires_crmv' => false, 'requires_technical_responsible' => false,
                'has_physical_address' => true, 'service_radius' => null,
                'service_categories' => [ServiceCategory::GROOMING, ServiceCategory::BOARDING],
                'equipment' => [], 'facilities' => $bothFacilities,
                'differentials' => [...$common, ProfessionalDifferential::DELIVERY_AVAILABLE, ProfessionalDifferential::ONLINE_ORDERING],
                'species_served' => RequirementLevel::REQUIRED, 'sizes_served' => RequirementLevel::OPTIONAL,
                'additional_fields' => $languages,
                'documents' => self::businessDocuments(false), 'steps' => self::businessSteps(false),
            ],
            'pet_hotel' => [
                'identification' => 'cnpj', 'requires_crmv' => false, 'requires_technical_responsible' => false,
                'has_physical_address' => true, 'service_radius' => null,
                'service_categories' => [ServiceCategory::BOARDING, ServiceCategory::GROOMING, ServiceCategory::TRAINING],
                'equipment' => [], 'facilities' => $bothFacilities,
                'differentials' => [
                    ...$common, ProfessionalDifferential::CAGE_FREE_OPTION,
                    ProfessionalDifferential::WEBCAM_ACCESS, ProfessionalDifferential::SPECIAL_DIET_ACCOMMODATION,
                ],
                'species_served' => RequirementLevel::REQUIRED, 'sizes_served' => RequirementLevel::REQUIRED,
                'additional_fields' => $languages,
                'documents' => self::businessDocuments(false), 'steps' => self::businessSteps(false),
            ],
            'grooming' => [
                'identification' => 'cnpj', 'requires_crmv' => false, 'requires_technical_responsible' => false,
                'has_physical_address' => true, 'service_radius' => RequirementLevel::OPTIONAL,
                'service_categories' => [ServiceCategory::GROOMING],
                'equipment' => [], 'facilities' => $bothFacilities,
                'differentials' => [...$common, ProfessionalDifferential::MOBILE_SERVICE],
                'species_served' => RequirementLevel::REQUIRED, 'sizes_served' => RequirementLevel::REQUIRED,
                'additional_fields' => $languages,
                'documents' => self::businessDocuments(false), 'steps' => self::businessSteps(false),
            ],
            'training' => [
                'identification' => 'cnpj', 'requires_crmv' => false, 'requires_technical_responsible' => false,
                'has_physical_address' => true, 'service_radius' => RequirementLevel::OPTIONAL,
                'service_categories' => [ServiceCategory::TRAINING],
                'equipment' => [], 'facilities' => $bothFacilities,
                'differentials' => [
                    ...$common, ProfessionalDifferential::MOBILE_SERVICE, ProfessionalDifferential::GROUP_SESSIONS_AVAILABLE,
                ],
                'species_served' => RequirementLevel::REQUIRED, 'sizes_served' => RequirementLevel::OPTIONAL,
                'additional_fields' => [...$languages, ProfessionalAdditionalField::TRAINING_METHODOLOGY],
                'documents' => self::businessDocuments(false), 'steps' => self::businessSteps(false),
            ],
        ];
    }
}
