<?php

namespace Database\Seeders\Dataset;

use App\Enums\PetSpecies;
use App\Enums\ProfessionalType;

/**
 * Espécies atendidas — DERIVADAS da oferta (tipo + especialidades), nunca sorteadas à parte.
 *
 * ── O defeito que esta classe existe para corrigir ────────────────────────────────────
 * A versão anterior (`ProfessionalRowBuilder::speciesFor()`) olhava SÓ o tipo e devolvia
 * `['dog','cat']` para todo mundo, acrescentando exótico por sorteio. Medido na base gerada
 * por ela: **7.000 de 7.000 profissionais visíveis tinham `dog`** — ou seja, `?species=dog`
 * devolvia o catálogo inteiro e o filtro de espécie não excluía ninguém. O dono do produto
 * relatou isso como "a espécie não está sendo levada em consideração"; a busca estava certa,
 * o dado é que não separava nada. O caso concreto que ele viu (confirmado no psql) era um
 * `vet` cuja ÚNICA especialidade é "Medicina Felina" declarando que atende cães.
 *
 * ── Os dois princípios ────────────────────────────────────────────────────────────────
 * 1. **Especialidade espécie-específica restringe.** "Medicina Felina" sozinha significa
 *    gato e só gato; "Medicina de Animais Silvestres/Exoticos" traz ave, réptil e roedor.
 *    Convivendo com outra especialidade, o recorte volta a ser amplo — um dermatologista que
 *    também é felinista atende cão, e isso é legítimo.
 * 2. **Tipo define o piso, não o teto.** Adestramento é prática majoritariamente canina;
 *    banho e tosa e hotel têm uma fatia real de casas exclusivas (só cão de um lado,
 *    gateria do outro); petshop e laboratório atendem pequenos animais além de cão e gato.
 *
 * ── O critério que importa para o produto ─────────────────────────────────────────────
 * A distribuição não precisa ser estatisticamente realista; precisa ser **separável**. Se
 * toda linha continuasse com `dog`+`cat`, o filtro seguiria inútil mesmo "corrigido". As
 * frações abaixo existem para que `?species=cat`, `?species=dog` e `?species=bird` devolvam
 * conjuntos DIFERENTES entre si e MENORES que o catálogo.
 */
final class SpeciesCoverage
{
    private const FELINE_SPECIALTY = 'Medicina Felina';

    private const EXOTIC_SPECIALTY = 'Medicina de Animais Silvestres/Exoticos';

    /** @var list<string> */
    private const EXOTIC_SPECIES = [PetSpecies::BIRD->value, PetSpecies::RODENT->value, PetSpecies::REPTILE->value];

    /** @var list<string> */
    private const DOG_AND_CAT = [PetSpecies::DOG->value, PetSpecies::CAT->value];

    /** Um em cada N cadastros de banho e tosa / hotel atende só cão. */
    private const CANINE_ONLY_SHARE = 4;

    /** Um em cada N cadastros de banho e tosa / hotel é exclusivo felino ("gateria"). */
    private const FELINE_ONLY_SHARE = 8;

    /** Um em cada N adestradores também trabalha com gato. */
    private const FELINE_TRAINING_SHARE = 6;

    /** Um em cada N petshops / laboratórios atende pequenos animais além de cão e gato. */
    private const SMALL_PET_SHARE = 3;

    /**
     * @param  list<string>  $specialties  nomes canônicos de `VeterinarySpecialtyCatalog`
     * @return list<string> valores de `PetSpecies`
     */
    public static function planFor(ProfessionalType $type, array $specialties): array
    {
        if (in_array(self::EXOTIC_SPECIALTY, $specialties, true)) {
            return self::exoticCoverage($specialties);
        }

        if (self::isFelineExclusive($specialties)) {
            return [PetSpecies::CAT->value];
        }

        return self::coverageByType($type);
    }

    /**
     * Silvestres/exóticos como ÚNICA especialidade = cadastro exclusivamente exótico. É a
     * minoria que torna `?species=bird` separável de `?species=dog` — sem ela, todo exótico
     * viria acompanhado de cão e gato e o filtro de ave nunca excluiria um cadastro canino.
     *
     * @param  list<string>  $specialties
     * @return list<string>
     */
    private static function exoticCoverage(array $specialties): array
    {
        $exotic = DatasetRandom::pickMany(self::EXOTIC_SPECIES, 2, 3);

        return count($specialties) === 1 ? $exotic : [...self::DOG_AND_CAT, ...$exotic];
    }

    /**
     * @param  list<string>  $specialties
     */
    private static function isFelineExclusive(array $specialties): bool
    {
        return $specialties === [self::FELINE_SPECIALTY];
    }

    /**
     * @return list<string>
     */
    private static function coverageByType(ProfessionalType $type): array
    {
        return match ($type) {
            ProfessionalType::TRAINING => self::trainingCoverage(),
            ProfessionalType::GROOMING, ProfessionalType::PET_HOTEL => self::petCareCoverage(),
            ProfessionalType::PETSHOP, ProfessionalType::LABORATORY => self::smallPetCoverage(),
            ProfessionalType::VET, ProfessionalType::CLINIC => self::DOG_AND_CAT,
        };
    }

    /**
     * @return list<string>
     */
    private static function trainingCoverage(): array
    {
        return DatasetRandom::chance(self::FELINE_TRAINING_SHARE)
            ? self::DOG_AND_CAT
            : [PetSpecies::DOG->value];
    }

    /**
     * @return list<string>
     */
    private static function petCareCoverage(): array
    {
        if (DatasetRandom::chance(self::FELINE_ONLY_SHARE)) {
            return [PetSpecies::CAT->value];
        }

        return DatasetRandom::chance(self::CANINE_ONLY_SHARE)
            ? [PetSpecies::DOG->value]
            : self::DOG_AND_CAT;
    }

    /**
     * @return list<string>
     */
    private static function smallPetCoverage(): array
    {
        return DatasetRandom::chance(self::SMALL_PET_SHARE)
            ? [...self::DOG_AND_CAT, ...DatasetRandom::pickMany(self::EXOTIC_SPECIES, 1, 2)]
            : self::DOG_AND_CAT;
    }
}
