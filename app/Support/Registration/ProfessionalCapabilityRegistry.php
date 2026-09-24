<?php

namespace App\Support\Registration;

use App\Enums\ProfessionalType;
use InvalidArgumentException;

/**
 * Fonte única de consulta da matriz `ProfessionalType × capacidades`
 * (`docs/segmentacao-cadastro-profissional.md` §2-4) — os dados em si moram em
 * `ProfessionalCapabilityDefinitions` (extraída para não estourar o limite de linhas de
 * classe nova). Quem chama nunca deve escrever `if ($type === ProfessionalType::VET)` de
 * novo — adicionar um tipo novo é adicionar uma entrada na definição, não caçar condicional
 * em Form Request/Controller/Vue.
 *
 * Consumida por três lugares: `ProfessionalSchemaBuilder` (endpoint que serve a matriz ao
 * front), `HasProfessionalCapabilityRules` (Form Request rejeita o que a UI não deveria ter
 * oferecido) e `RegistrationCompletionService` (persistência).
 */
final class ProfessionalCapabilityRegistry
{
    public static function for(ProfessionalType $type): ProfessionalTypeCapabilities
    {
        $definition = ProfessionalCapabilityDefinitions::all()[$type->value] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Nenhuma capacidade definida para o tipo {$type->value}.");
        }

        return new ProfessionalTypeCapabilities(
            type: $type,
            identification: $definition['identification'],
            requiresCrmv: $definition['requires_crmv'],
            requiresTechnicalResponsible: $definition['requires_technical_responsible'],
            hasPhysicalAddress: $definition['has_physical_address'],
            serviceRadius: $definition['service_radius'],
            serviceCategories: $definition['service_categories'],
            equipment: $definition['equipment'],
            facilities: $definition['facilities'],
            differentials: $definition['differentials'],
            speciesServed: $definition['species_served'],
            sizesServed: $definition['sizes_served'],
            additionalFields: $definition['additional_fields'],
            documents: $definition['documents'],
            steps: $definition['steps'],
        );
    }

    /**
     * Tipos VOLANTES: atendem em deslocamento, sem endereço físico aberto ao público (hoje só
     * `vet`). Para eles o raio de atendimento limita a busca e o endereço — que costuma ser
     * residencial — nunca sai na API pública. Derivado de `has_physical_address`, nunca de
     * uma lista redigitada.
     *
     * @return list<ProfessionalType>
     */
    public static function mobileTypes(): array
    {
        return array_values(array_map(
            fn (ProfessionalTypeCapabilities $capabilities): ProfessionalType => $capabilities->type,
            array_filter(self::all(), fn (ProfessionalTypeCapabilities $capabilities): bool => ! $capabilities->hasPhysicalAddress),
        ));
    }

    /** @return list<ProfessionalTypeCapabilities> */
    public static function all(): array
    {
        return array_map(
            fn (string $value): ProfessionalTypeCapabilities => self::for(ProfessionalType::from($value)),
            array_keys(ProfessionalCapabilityDefinitions::all())
        );
    }
}
