<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\SearchConcept;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalServiceItem;
use App\Support\Catalog\ProfessionalOfferingMatrix;
use App\Support\Registration\ServiceEquipmentDependency;

/**
 * A camada 2 da hierarquia (`RelevanceLayer::STRUCTURAL`) traduzida em valores de coluna:
 * dado o conceito buscado, que itens de `professionals.services_offered` e que itens de
 * `professionals.equipment` provam que o profissional presta aquilo.
 *
 * Tudo é DERIVADO da `ServiceCategory` do conceito, nunca escrito à mão — é a mesma matriz
 * de domínio que o cadastro valida (`ProfessionalOfferingMatrix`,
 * `ServiceEquipmentDependency`). Uma segunda tabela "conceito → equipamento" divergiria da
 * matriz na primeira mudança, e a divergência apareceria como resultado de busca errado.
 *
 * ── Por que `services_offered` importa ────────────────────────────────────────────────
 * É a resposta ao efeito colateral conhecido da regra de qualificação: profissional
 * recém-cadastrado que ainda não criou linhas em `services` sumiria das buscas conceituais.
 * Ele não some — o cadastro (desde a Onda 3) OBRIGA a declarar `services_offered`, e essa
 * declaração é granular por serviço, não um tipo genérico. "Declarei que faço banho" é
 * evidência muito mais forte que "sou do tipo banho e tosa", e continua excluindo
 * corretamente o hospital que declarou clínica geral e vacinação.
 *
 * A comparação é ESTRUTURAL (contenção em JSON), não textual: `services_offered` guarda
 * slugs em inglês (`bath`, `xray`, `castration`) e o termo buscado é português. Trigrama
 * entre "banho" e `["bath"]` não casaria nunca.
 */
final class ConceptStructuralEvidence
{
    /**
     * Valores de `services_offered` que satisfazem o conceito: todos os itens granulares da
     * categoria dele.
     *
     * @return list<string>
     */
    public static function declaredServiceValues(SearchConcept $concept): array
    {
        if ($concept->serviceCategory === null) {
            return [];
        }

        return array_map(
            static fn (ProfessionalServiceItem $item): string => $item->value,
            ProfessionalOfferingMatrix::serviceItemsFor($concept->serviceCategory),
        );
    }

    /**
     * Valores de `equipment` que satisfazem o conceito. Só as categorias com dependência de
     * equipamento (`imaging`, `laboratory`) devolvem algo — é o que faz "raio-x" e
     * "ultrassom" encontrarem quem tem o aparelho declarado, mesmo que o serviço esteja
     * cadastrado com outro nome.
     *
     * @return list<string>
     */
    public static function equipmentValues(SearchConcept $concept): array
    {
        if ($concept->serviceCategory === null) {
            return [];
        }

        return array_map(
            static fn (ClinicalEquipment $item): string => $item->value,
            ServiceEquipmentDependency::requiredEquipmentFor($concept->serviceCategory),
        );
    }
}
