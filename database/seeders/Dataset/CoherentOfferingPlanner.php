<?php

namespace Database\Seeders\Dataset;

use App\Enums\ProfessionalType;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalServiceItem;
use App\Enums\ServiceCategory;
use App\Support\Catalog\ProfessionalOfferingMatrix;
use App\Support\Catalog\VeterinarySpecialtyCatalog;

/**
 * Decide a oferta de um profissional — e nada aqui pode sair da matriz
 * (`ProfessionalOfferingMatrix`). Toda a coerência do dataset nasce nesta classe:
 *
 * 1. categorias ⊂ categorias do tipo (é o que impede vet volante de fazer banho e tosa e
 *    petshop de fazer consulta);
 * 2. itens de serviço ⊂ itens da categoria escolhida (nome do serviço bate com a categoria);
 * 3. especialidades ⊂ especialidades cujo `gate` está entre as categorias ESCOLHIDAS — mais
 *    estrito do que "elegíveis para o tipo": uma clínica que não vende cirurgia também não
 *    declara "Cirurgia Geral";
 * 4. equipamento ⊇ o exigido pelas categorias escolhidas (quem oferece raio-X tem o
 *    aparelho), nunca fora do que o tipo pode ter;
 * 5. espécies atendidas derivam do tipo MAIS das especialidades já escolhidas
 *    (`SpeciesCoverage`) — é o que impede um vet cuja única especialidade é Medicina Felina
 *    de declarar que atende cães.
 */
final class CoherentOfferingPlanner
{
    /** Nenhum cadastro oferece o catálogo inteiro: entre 2 e 5 categorias. */
    private const MIN_CATEGORIES = 2;

    private const MAX_CATEGORIES = 5;

    public static function planFor(ProfessionalType $type): CoherentOffering
    {
        $categories = self::pickCategories($type);
        $serviceItems = self::pickServiceItems($categories);
        $specialties = self::pickSpecialties($type, $categories);

        return new CoherentOffering(
            categories: $categories,
            serviceItems: $serviceItems,
            specialties: $specialties,
            equipment: self::pickEquipment($type, $categories),
            species: SpeciesCoverage::planFor($type, $specialties),
        );
    }

    /**
     * A primeira categoria da matriz é sempre incluída: é a atividade-fim do tipo
     * (`grooming` para banho e tosa, `consultation` para vet e clínica, `imaging` para
     * laboratório, `training` para adestramento). Sem essa âncora, o sorteio produziria um
     * "banho e tosa" que só faz hospedagem — coerente com a matriz e absurdo no mundo real.
     *
     * @return list<ServiceCategory>
     */
    private static function pickCategories(ProfessionalType $type): array
    {
        $allowed = ProfessionalOfferingMatrix::serviceCategoriesFor($type);
        $anchor = $allowed[0];
        $optional = array_values(array_filter($allowed, static fn (ServiceCategory $c): bool => $c !== $anchor));

        $extra = DatasetRandom::pickMany($optional, self::MIN_CATEGORIES - 1, self::MAX_CATEGORIES - 1);

        return [$anchor, ...$extra];
    }

    /**
     * @param  list<ServiceCategory>  $categories
     * @return list<ProfessionalServiceItem>
     */
    private static function pickServiceItems(array $categories): array
    {
        $items = [];

        foreach ($categories as $category) {
            $items = [...$items, ...self::itemsForCategory($category)];
        }

        return $items;
    }

    /**
     * Categoria cujo valor granular É a própria categoria (`consultation`, `vaccination`,
     * `boarding`, `hospitalization`) não tem item no catálogo granular — ver o docblock de
     * `ProfessionalServiceItem`. Nesses casos a lista vem vazia e quem monta a linha de
     * serviço cai no rótulo da própria categoria.
     *
     * @return list<ProfessionalServiceItem>
     */
    private static function itemsForCategory(ServiceCategory $category): array
    {
        $available = ProfessionalOfferingMatrix::serviceItemsFor($category);

        return $available === [] ? [] : DatasetRandom::pickMany($available, 1, 3);
    }

    /**
     * @param  list<ServiceCategory>  $categories
     * @return list<string>
     */
    private static function pickSpecialties(ProfessionalType $type, array $categories): array
    {
        $eligible = ProfessionalOfferingMatrix::specialtiesFor($type);

        $supported = array_values(array_filter(
            VeterinarySpecialtyCatalog::all(),
            static fn (array $specialty): bool => in_array($specialty['name'], $eligible, true)
                && in_array($specialty['gate'], $categories, true),
        ));

        return array_map(
            static fn (array $specialty): string => $specialty['name'],
            DatasetRandom::pickMany($supported, 1, 3),
        );
    }

    /**
     * O exigido pelas categorias (dependência do cadastro real) mais um extra opcional do que
     * o tipo pode ter — um hospital com centro cirúrgico e farmácia é plausível; um banho e
     * tosa com raio-X não é, e a matriz já garante que a lista dele é vazia.
     *
     * @param  list<ServiceCategory>  $categories
     * @return list<ClinicalEquipment>
     */
    private static function pickEquipment(ProfessionalType $type, array $categories): array
    {
        $required = [];

        foreach ($categories as $category) {
            $required = [...$required, ...ProfessionalOfferingMatrix::equipmentRequiredBy($type, $category)];
        }

        $extras = DatasetRandom::pickMany(ProfessionalOfferingMatrix::equipmentFor($type), 0, 3);

        return array_values(array_unique([...$required, ...$extras], SORT_REGULAR));
    }
}
