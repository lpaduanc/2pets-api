<?php

namespace App\Support\Catalog;

use App\Enums\ProfessionalType;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalServiceItem;
use App\Enums\ServiceCategory;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use App\Support\Registration\ServiceEquipmentDependency;

/**
 * A matriz `ProfessionalType × serviços × especialidades × equipamentos` — fonte única de
 * "o que este tipo de profissional pode legitimamente oferecer".
 *
 * ── Por que ela COMPÕE em vez de declarar ─────────────────────────────────────────────
 * A matriz tipo × categoria de serviço × equipamento já existia e já é obrigatória no
 * cadastro (`ProfessionalCapabilityRegistry` + `HasProfessionalCapabilityRules`, Onda 3).
 * Escrever uma segunda cópia aqui para uso do seeder criaria duas verdades que divergem na
 * primeira mudança — e a divergência apareceria como dado absurdo na busca, que é
 * exatamente o defeito que esta classe existe para impedir ("Hospital Veterinário Freitas"
 * marcado como banho e tosa com serviços de clínica geral e vacinação).
 *
 * O que esta classe ACRESCENTA sobre o registro de capacidades é uma coisa só: a
 * elegibilidade de especialidade, e mesmo ela é DERIVADA, não declarada — ver
 * `VeterinarySpecialtyCatalog` (`gate`).
 *
 * ── Consequências de domínio que caem sozinhas ────────────────────────────────────────
 * - `petshop` não tem `CONSULTATION`, `EMERGENCY` nem `SURGERY` no registro de capacidades,
 *   então não pode oferecer consulta médica veterinária nem declarar especialidade. Isso é
 *   restrição do CFMV (petshop sem responsável técnico veterinário), não preferência —
 *   `.claude/agents/pet-business-specialist.md` §"Cadastro de profissionais" item 3.
 * - `grooming` e `training` não têm equipamento clínico nenhum: banho e tosa não oferece
 *   raio-X nem ultrassom porque não tem a estrutura.
 * - `vet` (volante) tem `IMAGING`/`LABORATORY` com equipamento PORTÁTIL, mas não tem
 *   `SURGERY` nem `HOSPITALIZATION`: dependem de ambiente controlado, não de aparelho.
 *
 * Consumidores: `Database\Seeders\Dataset\*` (gera o cadastro) e os testes de relevância da
 * busca ("busca por X só retorna quem realmente oferece X"), que usam esta matriz como
 * gabarito em vez de repetir a expectativa à mão.
 */
final class ProfessionalOfferingMatrix
{
    /**
     * As categorias de serviço que o tipo pode oferecer — delegado direto ao registro de
     * capacidades do cadastro, sem cópia local.
     *
     * @return list<ServiceCategory>
     */
    public static function serviceCategoriesFor(ProfessionalType $type): array
    {
        return ProfessionalCapabilityRegistry::for($type)->serviceCategories;
    }

    public static function allowsServiceCategory(ProfessionalType $type, ServiceCategory $category): bool
    {
        return ProfessionalCapabilityRegistry::for($type)->allowsServiceCategory($category);
    }

    /**
     * Equipamento clínico que o tipo pode declarar.
     *
     * @return list<ClinicalEquipment>
     */
    public static function equipmentFor(ProfessionalType $type): array
    {
        return ProfessionalCapabilityRegistry::for($type)->equipment;
    }

    /**
     * Especialidades (nomes canônicos do catálogo) que o tipo pode declarar.
     *
     * Derivada: a especialidade entra se a `ServiceCategory` do seu `gate` estiver entre as
     * do tipo. Nenhuma tabela tipo × especialidade escrita à mão — adicionar uma
     * especialidade nova ao catálogo já a distribui pelos tipos certos, e mudar as
     * capacidades de um tipo já reajusta as especialidades dele.
     *
     * @return list<string>
     */
    public static function specialtiesFor(ProfessionalType $type): array
    {
        $capabilities = ProfessionalCapabilityRegistry::for($type);

        $eligible = array_filter(
            VeterinarySpecialtyCatalog::all(),
            static fn (array $specialty): bool => $capabilities->allowsServiceCategory($specialty['gate']),
        );

        return array_values(array_map(
            static fn (array $specialty): string => $specialty['name'],
            $eligible,
        ));
    }

    /**
     * Os itens granulares do catálogo que pertencem à categoria — é deles que sai o NOME do
     * serviço cadastrado ("Raio-X", "Castração", "Banho"), via `label()`. Sem isso o seeder
     * inventaria nome, e nome inventado é como a base atual passou a ter "Clínica
     * Veterinária X" oferecendo "banho e tosa".
     *
     * @return list<ProfessionalServiceItem>
     */
    public static function serviceItemsFor(ServiceCategory $category): array
    {
        return array_values(array_filter(
            ProfessionalServiceItem::cases(),
            static fn (ProfessionalServiceItem $item): bool => $item->category() === $category,
        ));
    }

    /**
     * Equipamento que o tipo precisa declarar para a categoria ser coerente, limitado ao que
     * o tipo pode ter. Quem oferece `imaging` tem aparelho de imagem; quem oferece
     * `laboratory` tem analisador ou laboratório. A regra já é validada no cadastro
     * (`ServiceEquipmentDependency`) — aqui ela é aplicada na GERAÇÃO, para o dado nascer
     * passando na própria validação.
     *
     * @return list<ClinicalEquipment>
     */
    public static function equipmentRequiredBy(ProfessionalType $type, ServiceCategory $category): array
    {
        $allowed = self::equipmentFor($type);

        return array_values(array_filter(
            ServiceEquipmentDependency::requiredEquipmentFor($category),
            static fn (ClinicalEquipment $item): bool => in_array($item, $allowed, true),
        ));
    }
}
