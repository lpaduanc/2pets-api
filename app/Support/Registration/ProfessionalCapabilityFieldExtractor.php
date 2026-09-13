<?php

namespace App\Support\Registration;

use App\Enums\Registration\ProfessionalAdditionalField;
use App\Enums\Registration\ProfessionalDifferential;
use App\Enums\Registration\ProfessionalFacility;

/**
 * Extrai, do payload já validado de conclusão/edição de cadastro, as espécies/portes
 * atendidos e os 18 campos de "Diferenciais e Facilidades"
 * (`docs/segmentacao-cadastro-profissional.md` §2/§4.3) — comuns aos fluxos de vet, negócio
 * genérico (`RegistrationCompletionService`) e edição de perfil (`LinkedProfileUpdateService`).
 *
 * Segunda camada de defesa, não só espelho da validação: `HasProfessionalCapabilityRules`
 * barra o cliente de AFIRMAR uma capacidade que o tipo não tem (`true`/valor real), mas
 * `false`/`0`/vazio passam a trava de propósito (ver `App\Rules\ProhibitedCapabilityValue`
 * — um front que sempre serializa o campo do formulário, mesmo escondido, manda esses
 * valores "neutros" por padrão). Esta classe é quem garante que, mesmo assim, a COLUNA de
 * uma capacidade não aplicável ao tipo nunca é escrita: força `null`/`[]` para o tipo atual,
 * independente do que veio em `$data`.
 */
final class ProfessionalCapabilityFieldExtractor
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function extract(array $data, ProfessionalTypeCapabilities $capabilities): array
    {
        return [
            'species_served' => $capabilities->speciesServed === null ? [] : ($data['species_served'] ?? []),
            'sizes_served' => $capabilities->sizesServed === null ? [] : ($data['sizes_served'] ?? []),
            ...self::facilityValues($data, $capabilities),
            ...self::differentialValues($data, $capabilities),
            ...self::additionalFieldValues($data, $capabilities),
        ];
    }

    /**
     * Mesma neutralização de `extract()`, mas para um PATCH parcial (`PUT /api/profile`):
     * uma chave AUSENTE de `$data` continua ausente no retorno — sem isso, todo PATCH
     * (mesmo um que só edite `business_name`) reintroduziria os 20 campos de capacidade
     * como `null`/`[]`, sobrescrevendo valores que este PATCH nem tocou. Só a chave que o
     * cliente REALMENTE enviou é corrigida (neutralizada, se não aplicável ao tipo).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function scrubForPatch(array $data, ProfessionalTypeCapabilities $capabilities): array
    {
        $typeSafeValues = array_intersect_key(self::extract($data, $capabilities), $data);

        return [...$data, ...$typeSafeValues];
    }

    /** @param  array<string, mixed>  $data @return array<string, bool|null> */
    private static function facilityValues(array $data, ProfessionalTypeCapabilities $capabilities): array
    {
        $values = [];

        foreach (ProfessionalFacility::cases() as $facility) {
            $values[$facility->value] = $capabilities->allowsFacility($facility) ? ($data[$facility->value] ?? null) : null;
        }

        return $values;
    }

    /** @param  array<string, mixed>  $data @return array<string, bool|null> */
    private static function differentialValues(array $data, ProfessionalTypeCapabilities $capabilities): array
    {
        $values = [];

        foreach (ProfessionalDifferential::cases() as $differential) {
            $values[$differential->value] = $capabilities->allowsDifferential($differential) ? ($data[$differential->value] ?? null) : null;
        }

        return $values;
    }

    /** @param  array<string, mixed>  $data @return array<string, mixed> */
    private static function additionalFieldValues(array $data, ProfessionalTypeCapabilities $capabilities): array
    {
        $values = [];

        foreach (ProfessionalAdditionalField::cases() as $field) {
            $values[$field->value] = self::additionalFieldValue($data, $capabilities, $field);
        }

        return $values;
    }

    /** @param  array<string, mixed>  $data */
    private static function additionalFieldValue(array $data, ProfessionalTypeCapabilities $capabilities, ProfessionalAdditionalField $field): mixed
    {
        $emptyValue = $field === ProfessionalAdditionalField::LANGUAGES_SPOKEN ? [] : null;

        if (! $capabilities->allowsAdditionalField($field)) {
            return $emptyValue;
        }

        return $data[$field->value] ?? $emptyValue;
    }
}
