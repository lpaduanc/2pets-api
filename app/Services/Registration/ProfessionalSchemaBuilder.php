<?php

namespace App\Services\Registration;

use App\Enums\PetSpecies;
use App\Enums\ProfessionalType;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalAdditionalField;
use App\Enums\Registration\ProfessionalDifferential;
use App\Enums\Registration\ProfessionalFacility;
use App\Enums\ServiceCategory;
use App\Enums\SizeCategory;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use App\Support\Registration\ProfessionalTypeCapabilities;
use App\Support\Registration\ServiceCatalog;
use App\Support\Registration\ServiceEquipmentDependency;
use BackedEnum;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

/**
 * Monta o payload de `GET /register/professional-schema`: a matriz de
 * `ProfessionalCapabilityRegistry` traduzida para o formato que o frontend consome — front
 * NÃO tem cópia própria desta segmentação (`docs/segmentacao-cadastro-profissional.md`,
 * decisão "backend é fonte única"). Dado estático por request (não depende de banco), por
 * isso cacheado sem TTL — `CACHE_KEY_PREFIX` tem versão no nome para invalidar num deploy
 * que mude a matriz sem precisar de `cache:forget` manual em produção. Uma entrada de cache
 * por locale (`cacheKey()`) desde que os rótulos passaram a variar por `Accept-Language`.
 */
final class ProfessionalSchemaBuilder
{
    // v3: `service_items` — catálogo granular de serviços (valor + rótulo + categoria),
    // fonte única para o front parar de manter `constants/serviceCategoryMap.js` em
    // paralelo (P0 2026-09-13: a cópia local aceitava `services_offered` que a API
    // rejeitava — vermifugação, raio-X, castração e afins nunca completavam cadastro).
    // v4: chave passou a incluir o locale (ver `cacheKey()`) — os `label()` de
    // `labelsPayload()` agora resolvem por tradução (`SetLocaleFromAcceptLanguage`);
    // sem o locale na chave, o primeiro idioma pedido em produção "congelaria" o payload
    // pra todo mundo (`Cache::rememberForever` nunca expira sozinho).
    private const CACHE_KEY_PREFIX = 'registration:professional-schema:v4';

    /**
     * @return array{
     *     types: array<string, mixed>,
     *     labels: array<string, mixed>,
     *     service_equipment_dependencies: array<string, list<string>>,
     *     equipment_documents: array<string, string>,
     *     service_items: list<array{value: string, label: string, category: string}>,
     * }
     */
    public function build(): array
    {
        return Cache::rememberForever($this->cacheKey(), fn (): array => [
            'types' => $this->typesPayload(),
            'labels' => $this->labelsPayload(),
            // Front precisa disto para desabilitar um serviço enquanto o equipamento
            // correspondente não estiver marcado — sem isso a UI oferece o que a API rejeita.
            'service_equipment_dependencies' => ServiceEquipmentDependency::toSchemaPayload(),
            'equipment_documents' => $this->equipmentDocumentsPayload(),
            'service_items' => ServiceCatalog::itemsPayload(),
        ]);
    }

    /**
     * Uma entrada de cache por locale — `types.*.label`, `labels.*` e `service_items.*.label`
     * dependem de `App::getLocale()` no momento em que o `fn` do `rememberForever` roda.
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX.':'.App::getLocale();
    }

    /**
     * Vínculo equipamento↔documento (§1.3/§4 do documento): hoje só `xray_machine` exige
     * documento adicional além do que o cadastro já pede.
     *
     * @return array<string, string>
     */
    private function equipmentDocumentsPayload(): array
    {
        $payload = [];

        foreach (ClinicalEquipment::cases() as $item) {
            $documentType = $item->requiredDocumentType();

            if ($documentType !== null) {
                $payload[$item->value] = $documentType;
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function typesPayload(): array
    {
        $payload = [];

        foreach (ProfessionalType::cases() as $type) {
            $payload[$type->value] = $this->typePayload($type, ProfessionalCapabilityRegistry::for($type));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function typePayload(ProfessionalType $type, ProfessionalTypeCapabilities $capabilities): array
    {
        return [
            'label' => $type->label(),
            'identification' => $capabilities->identification,
            'requires_crmv' => $capabilities->requiresCrmv,
            'requires_technical_responsible' => $capabilities->requiresTechnicalResponsible,
            'has_physical_address' => $capabilities->hasPhysicalAddress,
            'service_radius' => $capabilities->serviceRadius?->value,
            'service_categories' => $this->values($capabilities->serviceCategories),
            'equipment' => $this->values($capabilities->equipment),
            'facilities' => $this->values($capabilities->facilities),
            'differentials' => $this->values($capabilities->differentials),
            'species_served' => $capabilities->speciesServed?->value,
            'sizes_served' => $capabilities->sizesServed?->value,
            'additional_fields' => array_map(
                fn (ProfessionalAdditionalField $field): array => ['key' => $field->value, 'type' => $field->dataType()],
                $capabilities->additionalFields,
            ),
            'documents' => $capabilities->documents,
            'steps' => $capabilities->steps,
        ];
    }

    /** @return array<string, mixed> */
    private function labelsPayload(): array
    {
        return [
            'professional_types' => $this->labelMap(ProfessionalType::cases()),
            'service_categories' => $this->labelMap(ServiceCategory::cases()),
            'equipment' => $this->labelMap(ClinicalEquipment::cases()),
            'facilities' => $this->labelMap(ProfessionalFacility::cases()),
            'differentials' => $this->labelMap(ProfessionalDifferential::cases()),
            'additional_fields' => $this->labelMap(ProfessionalAdditionalField::cases()),
            'species' => $this->labelMap(PetSpecies::cases()),
            'sizes' => $this->labelMap(SizeCategory::cases()),
        ];
    }

    /**
     * @param  list<PetSpecies|SizeCategory|ProfessionalType|ServiceCategory|ClinicalEquipment|ProfessionalFacility|ProfessionalDifferential|ProfessionalAdditionalField>  $cases
     * @return array<string, string>
     */
    private function labelMap(array $cases): array
    {
        $labels = array_map(static fn ($case): string => $case->label(), $cases);

        return array_combine($this->values($cases), $labels);
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    private function values(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    }
}
