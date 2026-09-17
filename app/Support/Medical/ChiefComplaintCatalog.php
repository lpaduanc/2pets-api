<?php

namespace App\Support\Medical;

use App\Enums\PetSpecies;

/**
 * Resolve os slugs válidos de `chief_complaint` para uma espécie — contrato
 * docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §5.4.
 *
 * Espécie sem lista própria (ave, réptil, roedor, peixe, outro) usa só os slugs comuns —
 * mesma lacuna deliberada do catálogo de vacinas, documentada no doc de domínio §3.2.
 */
final class ChiefComplaintCatalog
{
    /**
     * @return list<string>
     */
    public static function validValuesFor(?string $species): array
    {
        $config = config('clinical-parameters.chief_complaint');
        $values = $config['common'];

        if ($species === PetSpecies::DOG->value) {
            $values = [...$values, ...$config['dog']];
        }

        if ($species === PetSpecies::CAT->value) {
            $values = [...$values, ...$config['cat']];
        }

        return array_values(array_unique($values));
    }
}
