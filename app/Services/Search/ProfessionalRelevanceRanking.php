<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\FieldMatch;
use App\DataTransferObjects\Search\InterpretedSearchQuery;
use App\Enums\Search\RelevanceLayer;
use App\Enums\Search\SearchField;
use Illuminate\Database\Eloquent\Builder;

/**
 * A coluna `search_relevance` — "qual foi a evidência MAIS FORTE de que este profissional
 * atende o que foi buscado".
 *
 * Separada de `ProfessionalTextSearchQuery` porque responde outra pergunta: o filtro decide
 * QUEM entra, o ranking decide EM QUE ORDEM. Mudam por motivos diferentes.
 *
 * A ordem é a de `RelevanceLayer` e INVERTEU em 2026-09-14: evidência dura primeiro
 * (serviço, especialidade), nome por último. Antes o nome pesava 1,00 e o serviço 0,80, o
 * que colocava "Clínica Veterinária X" acima de quem realmente presta o serviço buscado.
 *
 * Dois regimes de cálculo, e a escolha de cada um é de latência medida:
 * - **Contínua** para campos da própria linha varrida (`users.name`) e para os campos de
 *   `professionals`, que vêm de UMA subquery escalar correlacionada. Contínuo importa: é o
 *   que separa "casamento exato" de "casamento parcial".
 * - **Tier plano** para tudo que exigiria varrer outra tabela por linha candidata — serviço,
 *   evidência estrutural e tipo. Medido: a versão contínua para serviços custava 619 ms dos
 *   1.097 ms do ranking num termo que casa 27 mil profissionais; o conjunto hasheado
 *   (`users.id IN (...)`) baixou para 514 ms. O que se perde é a gradação DENTRO do tier — e
 *   "serviço é a camada mais forte" é uma afirmação de tier, não de curva.
 */
final class ProfessionalRelevanceRanking
{
    public function __construct(
        private readonly RelevanceTierBuilder $tiers,
        private readonly SearchFieldMatcher $matcher,
    ) {}

    /**
     * Fração do tier de tipo que vira BÔNUS somado ao melhor casamento. Ver `applyTo()`.
     */
    private const BUSINESS_TYPE_BONUS_RATIO = 0.2;

    /**
     * `GREATEST(camadas) + tipo × 0,2`, e as duas parcelas existem por motivos diferentes.
     *
     * O `GREATEST` implementa a hierarquia: vale a evidência MAIS FORTE encontrada, e as
     * camadas estão suficientemente separadas (0,25) para que nenhuma de baixo ultrapasse
     * uma de cima. O tipo entra aí porque, no regime aberto ("clínica", "petshop"), ele pode
     * ser a ÚNICA evidência — sem isso, um petshop de nome neutro cairia abaixo de um banho e
     * tosa que por acaso se chama "Pet Shop Studio", que é a inversão exata do defeito que
     * esta correção existe para eliminar.
     *
     * A SOMA implementa o desempate. Sem ela o tipo seria decorativo: um resultado que
     * qualificou por evidência dura já vale 1,00, e `GREATEST(1,00; 0,50)` descarta o tipo
     * sempre. Com o bônus, entre dois estabelecimentos que ambos oferecem banho e tosa, o que
     * se cadastrou COMO banho e tosa sobe (1,10 contra 1,00) — que é exatamente o papel que o
     * produto reservou ao tipo: ranquear, nunca qualificar.
     *
     * O bônus (0,10) é menor que o intervalo entre camadas (0,25), então desempata DENTRO da
     * camada sem nunca promover alguém para a camada de cima.
     *
     * O tier de tipo aparece duas vezes no SQL de propósito. É uma subquery simples servida
     * por índice e o Postgres a resolve como subplan hasheado; medi o custo antes de aceitar
     * a duplicação (ver relato). A alternativa — materializar o tier numa CTE — trocaria uma
     * duplicação barata por uma barreira de otimização, que é o negócio ruim.
     */
    public function applyTo(Builder $query, InterpretedSearchQuery $interpreted): void
    {
        $businessType = $this->tiers->businessType($interpreted);

        $layers = array_values(array_filter([
            $this->continuous(SearchField::USER_NAME, $interpreted),
            $this->businessName($interpreted),
            $this->tiers->serviceName($interpreted),
            $this->tiers->specialty($interpreted),
            $this->tiers->structuralEvidence($interpreted),
            $businessType,
        ], static fn (?FieldMatch $layer): bool => $layer !== null));

        $greatest = sprintf('GREATEST(%s)', implode(', ', array_column($layers, 'sql')));
        $bindings = array_merge(...array_column($layers, 'bindings'));

        if ($businessType !== null) {
            $greatest .= sprintf(' + (%s) * %.2F', $businessType->sql, self::BUSINESS_TYPE_BONUS_RATIO);
            $bindings = [...$bindings, ...$businessType->bindings];
        }

        $query->selectRaw($greatest.' AS search_relevance', $bindings);
    }

    /**
     * Pontuação de um campo da própria tabela varrida. O regime (contínuo ou tier de
     * prefixo) é decidido por `SearchFieldMatcher`, o mesmo que decidiu o do filtro.
     */
    private function continuous(SearchField $field, InterpretedSearchQuery $interpreted): FieldMatch
    {
        return $this->matcher->score($field, $interpreted->phrase);
    }

    /**
     * Razão social, numa subquery escalar correlacionada — nunca LEFT JOIN, pelo motivo
     * medido em `ProfessionalTextSearchQuery`.
     *
     * É o ÚNICO campo de `professionals` que ainda pontua de forma contínua aqui.
     * `specialties` virou tier (ver `RelevanceTierBuilder::specialty()`) e as duas descrições
     * nunca pontuaram — `RelevanceLayer::DESCRIPTION` devolve peso `null`, por custo medido:
     * `word_similarity` sobre texto livre longo para toda linha candidata custava 2.075 ms
     * contra 1.137 ms sem.
     */
    private function businessName(InterpretedSearchQuery $interpreted): FieldMatch
    {
        $score = $this->matcher->score(SearchField::BUSINESS_NAME, $interpreted->phrase);

        return new FieldMatch(
            sprintf(
                'COALESCE((SELECT MAX(%s) FROM professionals'
                .' WHERE professionals.user_id = users.id AND professionals.deleted_at IS NULL), 0)',
                $score->sql,
            ),
            $score->bindings,
        );
    }
}
