<?php

namespace Database\Seeders\Dataset;

use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalServiceItem;
use App\Enums\ServiceCategory;

/**
 * O que UM profissional oferece, já coerente entre si: categorias, itens de serviço,
 * especialidades e equipamento.
 *
 * Imutável porque é o contrato entre quem PLANEJA a oferta (`CoherentOfferingPlanner`, que
 * conhece a matriz de domínio) e quem MONTA as linhas do banco (`ProfessionalRowBuilder`,
 * que não deve ter o direito de acrescentar um serviço que a matriz não autorizou — foi
 * exatamente esse tipo de escrita solta que produziu o "Hospital Veterinário Freitas" com
 * tipo de banho e tosa).
 */
final readonly class CoherentOffering
{
    /**
     * @param  list<ServiceCategory>  $categories
     * @param  list<ProfessionalServiceItem>  $serviceItems
     * @param  list<string>  $specialties  nomes canônicos de `VeterinarySpecialtyCatalog`
     * @param  list<ClinicalEquipment>  $equipment
     * @param  list<string>  $species  valores de `PetSpecies`, derivados de tipo + especialidades
     */
    public function __construct(
        public array $categories,
        public array $serviceItems,
        public array $specialties,
        public array $equipment,
        public array $species,
    ) {}

    /** @return list<string> */
    public function categoryValues(): array
    {
        return array_map(static fn (ServiceCategory $category): string => $category->value, $this->categories);
    }

    /**
     * O que vai para `professionals.services_offered`: os valores GRANULARES do catálogo
     * (`bath`, `xray`, `castration`), que é o formato que o cadastro real grava desde a
     * correção de P0 de 2026-09-13 — e o que a busca usa como evidência de camada 2 para
     * quem ainda não criou linhas em `services`.
     *
     * @return list<string>
     */
    public function serviceItemValues(): array
    {
        return array_values(array_unique(array_map(
            static fn (ProfessionalServiceItem $item): string => $item->value,
            $this->serviceItems,
        )));
    }

    /** @return list<string> */
    public function equipmentValues(): array
    {
        return array_values(array_unique(array_map(
            static fn (ClinicalEquipment $item): string => $item->value,
            $this->equipment,
        )));
    }

    public function offers(ServiceCategory $category): bool
    {
        return in_array($category, $this->categories, true);
    }

    /**
     * Se a categoria já rendeu algum item granular. Quatro categorias não têm item no
     * catálogo (`consultation`, `vaccination`, `boarding`, `hospitalization` — o valor
     * granular É o da própria categoria, ver `ProfessionalServiceItem`), e é este predicado
     * que faz o serviço delas ser criado com o rótulo da categoria em vez de sumir.
     */
    public function hasItemsIn(ServiceCategory $category): bool
    {
        foreach ($this->serviceItems as $item) {
            if ($item->category() === $category) {
                return true;
            }
        }

        return false;
    }
}
