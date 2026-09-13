<?php

namespace App\Http\Requests\Registration\Concerns;

use App\Enums\PetSpecies;
use App\Enums\ProfessionalType;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalAdditionalField;
use App\Enums\Registration\ProfessionalDifferential;
use App\Enums\Registration\ProfessionalFacility;
use App\Enums\Registration\RequirementLevel;
use App\Enums\SizeCategory;
use App\Rules\AllowedServiceValue;
use App\Rules\ProhibitedCapabilityValue;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use App\Support\Registration\ProfessionalTypeCapabilities;
use App\Support\Registration\ServiceCatalog;
use App\Support\Registration\ServiceEquipmentDependency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * A dupla trava do cadastro segmentado (`docs/segmentacao-cadastro-profissional.md`): a UI
 * já filtra o que mostra por tipo, mas a API precisa rejeitar sozinha, mesmo que alguém monte
 * o payload na mão. Toda regra aqui vem de `ProfessionalCapabilityRegistry` — nenhum
 * `if ($type === ...)` local, para o `CompleteVetRegistrationRequest` e o
 * `CompleteGenericProfessionalRegistrationRequest` não divergirem da matriz com o tempo.
 */
trait HasProfessionalCapabilityRules
{
    /** @return array<string, array<int, mixed>> */
    protected function capabilityRules(ProfessionalType $type): array
    {
        $capabilities = ProfessionalCapabilityRegistry::for($type);

        return [
            'species_served' => $this->requirementRules($capabilities->speciesServed),
            'species_served.*' => $capabilities->speciesServed === null ? [] : [Rule::enum(PetSpecies::class)],
            'sizes_served' => $this->requirementRules($capabilities->sizesServed),
            'sizes_served.*' => $capabilities->sizesServed === null ? [] : [Rule::enum(SizeCategory::class)],
            'services_offered' => ['nullable', 'array'],
            'services_offered.*' => [new AllowedServiceValue($capabilities)],
            'equipment' => $capabilities->equipment === [] ? ['prohibited'] : ['nullable', 'array'],
            'equipment.*' => $capabilities->equipment === [] ? [] : [Rule::in($capabilities->allowedEquipmentValues())],
            ...$this->facilityRules($capabilities),
            ...$this->differentialRules($capabilities),
            ...$this->additionalFieldRules($capabilities),
        ];
    }

    /** @return array<string, string> */
    protected function capabilityMessages(ProfessionalType $type): array
    {
        $capabilities = ProfessionalCapabilityRegistry::for($type);

        return [
            // `services_offered.*` não usa mais `Rule::in` — a mensagem de rejeição
            // (categoria/valor desconhecido) vem direto do `$fail()` de `AllowedServiceValue`.
            'equipment.*.in' => 'Este equipamento não está disponível para o tipo de cadastro selecionado.',
            'species_served.*.enum' => 'Selecione uma espécie válida.',
            'sizes_served.*.enum' => 'Selecione um porte válido.',
            ...($capabilities->equipment === [] ? ['equipment.prohibited' => $this->notAvailableMessage('Equipamentos')] : []),
            ...($capabilities->speciesServed === null ? ['species_served.prohibited' => $this->notAvailableMessage('Espécies atendidas')] : []),
            ...($capabilities->sizesServed === null ? ['sizes_served.prohibited' => $this->notAvailableMessage('Portes atendidos')] : []),
            ...($capabilities->serviceRadius === null ? ['service_radius_km.prohibited' => $this->notAvailableMessage('Raio de atendimento (km)')] : []),
            // Facilidades/diferenciais/campos adicionais NÃO usam mais a regra nativa
            // `prohibited` (ver `ProhibitedCapabilityValue`) — a mensagem viaja junto com a
            // regra, no `Closure $fail()` dela, não por chave `.prohibited` aqui.
        ];
    }

    /**
     * Regra nova de dependência serviço↔equipamento
     * (`docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.2): não se oferece serviço cujo
     * equipamento correspondente não foi declarado. É validação cruzada entre dois campos —
     * não cabe em `Rule::in` isolado, por isso cada Form Request chama este método a partir do
     * próprio `withValidator()`. Vale para os três tipos com `imaging`/`laboratory` no
     * catálogo (`vet`, `clinic`, `laboratory`); para os demais, `services_offered` nunca
     * contém essas categorias (já barrado por `capabilityRules()`), então o laço não encontra
     * nada para reportar.
     *
     * @param  list<mixed>  $servicesOffered
     * @param  list<mixed>  $equipment
     */
    protected function addServiceEquipmentDependencyErrors(
        Validator $validator,
        array $servicesOffered,
        array $equipment,
        string $servicesField = 'services_offered',
    ): void {
        $declaredEquipment = $this->toEquipmentEnums($equipment);

        foreach ($servicesOffered as $serviceValue) {
            // `ServiceCatalog::categoryFor()`, não `ServiceCategory::tryFrom()` direto: um
            // vet que marca o item granular `"xray"` (não a categoria `"imaging"`) precisa
            // do mesmo gate de equipamento — sem isto, a dependência nunca disparava para
            // quem envia o valor granular (P0 2026-09-13).
            $category = ServiceCatalog::categoryFor((string) $serviceValue);

            if ($category === null || ServiceEquipmentDependency::isSatisfiedBy($category, $declaredEquipment)) {
                continue;
            }

            $validator->errors()->add($servicesField, ServiceEquipmentDependency::missingEquipmentMessage($category));
        }
    }

    /**
     * @param  list<mixed>  $equipment
     * @return list<ClinicalEquipment>
     */
    private function toEquipmentEnums(array $equipment): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): ?ClinicalEquipment => ClinicalEquipment::tryFrom((string) $value),
            $equipment,
        )));
    }

    /**
     * Mesma matriz de `capabilityRules()`, adaptada para um PATCH parcial (`PUT /api/profile`):
     * cada regra ganha `sometimes` na frente (inclusive as `prohibited` — só bloqueiam quando o
     * campo É enviado, mesmo comportamento de `required`) e, opcionalmente, um prefixo de chave
     * (`professional.`) para casar com o payload aninhado do endpoint de perfil.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function capabilityPatchRules(ProfessionalType $type, string $prefix = ''): array
    {
        $patchRules = array_map(
            fn (array $ruleSet): array => ['sometimes', ...$ruleSet],
            $this->capabilityRules($type)
        );

        return $this->withKeyPrefix($prefix, $patchRules);
    }

    /** @return array<string, string> */
    protected function capabilityPatchMessages(ProfessionalType $type, string $prefix = ''): array
    {
        return $this->withKeyPrefix($prefix, $this->capabilityMessages($type));
    }

    /** @param array<string, mixed> $items @return array<string, mixed> */
    private function withKeyPrefix(string $prefix, array $items): array
    {
        if ($prefix === '') {
            return $items;
        }

        $prefixed = [];

        foreach ($items as $key => $value) {
            $prefixed[$prefix.$key] = $value;
        }

        return $prefixed;
    }

    /** @return list<string> */
    private function requirementRules(?RequirementLevel $level): array
    {
        return match ($level) {
            RequirementLevel::REQUIRED => ['required', 'array', 'min:1'],
            RequirementLevel::OPTIONAL => ['nullable', 'array'],
            null => ['prohibited'],
        };
    }

    /**
     * Raio de atendimento (km) — usado só pelo fluxo de negócio genérico
     * (`CompleteGenericProfessionalRegistrationRequest`). O vet tem a própria regra desde
     * antes desta matriz existir; deixada como está para não mudar, sem pedido explícito, um
     * comportamento (`nullable`) já coberto por teste em produção.
     *
     * @return list<string>
     */
    protected function serviceRadiusRules(?RequirementLevel $level): array
    {
        return match ($level) {
            RequirementLevel::REQUIRED => ['required', 'integer', 'min:1'],
            RequirementLevel::OPTIONAL => ['nullable', 'integer', 'min:1'],
            null => ['prohibited'],
        };
    }

    /** @return array<string, array<int, mixed>> */
    private function facilityRules(ProfessionalTypeCapabilities $capabilities): array
    {
        $rules = [];

        foreach (ProfessionalFacility::cases() as $facility) {
            $rules[$facility->value] = $capabilities->allowsFacility($facility)
                ? ['nullable', 'boolean']
                : [new ProhibitedCapabilityValue($this->notAvailableMessage($facility->label()))];
        }

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    private function differentialRules(ProfessionalTypeCapabilities $capabilities): array
    {
        $rules = [];

        foreach (ProfessionalDifferential::cases() as $differential) {
            $rules[$differential->value] = $capabilities->allowsDifferential($differential)
                ? ['nullable', 'boolean']
                : [new ProhibitedCapabilityValue($this->notAvailableMessage($differential->label()))];
        }

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    private function additionalFieldRules(ProfessionalTypeCapabilities $capabilities): array
    {
        $rules = [];

        foreach (ProfessionalAdditionalField::cases() as $field) {
            $rules = [...$rules, ...$this->ruleForAdditionalField($field, $capabilities->allowsAdditionalField($field))];
        }

        return $rules;
    }

    /**
     * `exam_rooms_count` é a única numérica do grupo: `0` é o "neutro" dela, o mesmo papel
     * que `false` cumpre para os booleanos de facilidade/diferencial (ver
     * `ProhibitedCapabilityValue`). `training_methodology`/`languages_spoken` não precisam
     * de valor extra — string vazia e array vazio já são neutros por padrão.
     *
     * @return array<string, array<int, mixed>>
     */
    private function ruleForAdditionalField(ProfessionalAdditionalField $field, bool $allowed): array
    {
        if (! $allowed) {
            $neutralValues = $field === ProfessionalAdditionalField::EXAM_ROOMS_COUNT ? [0] : [];

            return [$field->value => [new ProhibitedCapabilityValue($this->notAvailableMessage($field->label()), $neutralValues)]];
        }

        return match ($field) {
            ProfessionalAdditionalField::EXAM_ROOMS_COUNT => [$field->value => ['nullable', 'integer', 'min:0']],
            ProfessionalAdditionalField::TRAINING_METHODOLOGY => [$field->value => ['nullable', 'string']],
            ProfessionalAdditionalField::LANGUAGES_SPOKEN => [
                $field->value => ['nullable', 'array'],
                'languages_spoken.*' => ['string'],
            ],
        };
    }

    private function notAvailableMessage(string $label): string
    {
        return "O campo \"{$label}\" não é permitido para o tipo de cadastro selecionado.";
    }
}
