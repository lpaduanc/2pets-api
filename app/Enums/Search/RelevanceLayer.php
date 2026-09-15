<?php

namespace App\Enums\Search;

/**
 * A hierarquia de relevância da busca de profissionais, da evidência mais forte para a mais
 * fraca. É a mesma hierarquia que decide QUALIFICAÇÃO (quem entra) e RANKING (em que ordem),
 * por isso mora num lugar só.
 *
 * A ordem é decisão de produto, e ela INVERTEU em 2026-09-14. Antes o nome valia mais que
 * tudo (`users.name` 1,00, especialidade 0,90, serviço 0,80), o que fazia a busca premiar
 * quem se chamava "Clínica Veterinária X" acima de quem realmente prestava o serviço
 * procurado. A regra nova: **primeiro o que o profissional comprovadamente FAZ, por último
 * como ele se CHAMA.**
 *
 * | camada | do que é feita | qualifica termo conceitual? |
 * |---|---|---|
 * | `HARD_EVIDENCE` | `services.name` (ativo) + `professionals.specialties` | sim |
 * | `STRUCTURAL` | `services.category`, `services_offered` declarado, `equipment` | sim |
 * | `BUSINESS_TYPE` | `professionals.professional_type` | **não** (ver abaixo) |
 * | `NAME` | `users.name`, `professionals.business_name` | não |
 * | `DESCRIPTION` | `professionals.description`, `services.description` | não (nem pontua) |
 *
 * ── Por que `BUSINESS_TYPE` ranqueia mas não qualifica ────────────────────────────────
 * Tipo é PROMESSA DE CADASTRO, não evidência: qualquer um marca "banho e tosa" no cadastro
 * sem nunca ter cadastrado um serviço de banho. Foi exatamente essa a queixa que originou a
 * correção — um estabelecimento com `professional_type = grooming` cujos serviços eram
 * "clínica geral" e "vacinação" aparecia na busca por "banho e tosa".
 *
 * Exceção deliberada e estreita: quando o conceito buscado é SÓ um tipo de negócio e não
 * corresponde a nenhuma `ServiceCategory` ("clínica", "petshop", "veterinário"), não existe
 * evidência mais dura possível — não há serviço chamado "ser uma clínica". Nesses casos o
 * tipo qualifica, porque a alternativa seria tornar o termo inbuscável. Ver
 * `SearchConcept::requiresCompetenceEvidence()`.
 */
enum RelevanceLayer: string
{
    case HARD_EVIDENCE = 'hard_evidence';
    case STRUCTURAL = 'structural';
    case BUSINESS_TYPE = 'business_type';
    case NAME = 'name';
    case DESCRIPTION = 'description';

    /**
     * Peso base da camada. `null` = a camada FILTRA mas não pontua.
     *
     * As duas descrições ficam sem peso por custo medido: calcular `word_similarity` sobre
     * texto livre longo para TODA linha candidata custou 2.075 ms contra 1.137 ms sem, e
     * quem casou só pela descrição fica com relevância ~0 e vai para o fim — que é onde o
     * produto queria que ficasse.
     *
     * Os intervalos entre camadas são largos de propósito (0,25 entre camadas vizinhas):
     * como o peso MULTIPLICA a similaridade (0..1), um intervalo estreito deixaria um
     * casamento perfeito de camada inferior ultrapassar um casamento parcial de camada
     * superior, e a hierarquia deixaria de valer na prática.
     */
    public function weight(): ?float
    {
        return match ($this) {
            self::HARD_EVIDENCE => 1.00,
            self::STRUCTURAL => 0.75,
            self::BUSINESS_TYPE => 0.50,
            self::NAME => 0.25,
            self::DESCRIPTION => null,
        };
    }

    /** As camadas que satisfazem um termo conceitual — a regra de qualificação. */
    public function qualifiesConceptualTerm(): bool
    {
        return $this === self::HARD_EVIDENCE || $this === self::STRUCTURAL;
    }
}
