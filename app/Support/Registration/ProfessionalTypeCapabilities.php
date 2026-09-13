<?php

namespace App\Support\Registration;

use App\Enums\ProfessionalType;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalAdditionalField;
use App\Enums\Registration\ProfessionalDifferential;
use App\Enums\Registration\ProfessionalFacility;
use App\Enums\Registration\RequirementLevel;
use App\Enums\ServiceCategory;

/**
 * O que um tipo de profissional PODE ver/enviar no cadastro — a matriz de
 * `docs/segmentacao-cadastro-profissional.md` §2-4 como dado consultável, nunca como `if`
 * espalhado. Uma instância descreve UM `ProfessionalType`; ver `ProfessionalCapabilityRegistry`
 * para a fonte dos dados e `for()` para construir a partir dela.
 */
final readonly class ProfessionalTypeCapabilities
{
    /**
     * @param  'cpf'|'cnpj'  $identification
     * @param  list<ServiceCategory>  $serviceCategories
     * @param  list<ClinicalEquipment>  $equipment
     * @param  list<ProfessionalFacility>  $facilities
     * @param  list<ProfessionalDifferential>  $differentials
     * @param  list<ProfessionalAdditionalField>  $additionalFields
     * @param  list<string>  $documents
     * @param  list<string>  $steps
     */
    public function __construct(
        public ProfessionalType $type,
        public string $identification,
        public bool $requiresCrmv,
        public bool $requiresTechnicalResponsible,
        public bool $hasPhysicalAddress,
        public ?RequirementLevel $serviceRadius,
        public array $serviceCategories,
        public array $equipment,
        public array $facilities,
        public array $differentials,
        public ?RequirementLevel $speciesServed,
        public ?RequirementLevel $sizesServed,
        public array $additionalFields,
        public array $documents,
        public array $steps,
    ) {}

    public function allowsServiceCategory(ServiceCategory $category): bool
    {
        return in_array($category, $this->serviceCategories, true);
    }

    public function allowsEquipment(ClinicalEquipment $item): bool
    {
        return in_array($item, $this->equipment, true);
    }

    public function allowsFacility(ProfessionalFacility $facility): bool
    {
        return in_array($facility, $this->facilities, true);
    }

    public function allowsDifferential(ProfessionalDifferential $differential): bool
    {
        return in_array($differential, $this->differentials, true);
    }

    public function allowsAdditionalField(ProfessionalAdditionalField $field): bool
    {
        return in_array($field, $this->additionalFields, true);
    }

    /** @return list<string> */
    public function allowedEquipmentValues(): array
    {
        return array_map(fn (ClinicalEquipment $item): string => $item->value, $this->equipment);
    }
}
