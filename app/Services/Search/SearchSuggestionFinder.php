<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\SearchConcept;
use App\DataTransferObjects\Search\SearchSuggestion;
use App\DataTransferObjects\SearchFiltersDTO;
use App\Exceptions\Search\SearchCountTimedOutException;

/**
 * O "você quis dizer..." da busca pública.
 *
 * ── O problema medido ─────────────────────────────────────────────────────────────────
 * `?query=car` devolve creche e hotel no topo porque "car" casa legitimamente com
 * "Day **Car**e". Os cardiologistas ESTÃO no conjunto, só ranqueados abaixo — o ranking não
 * está errado, o termo é que é curto demais para decidir.
 *
 * ── O que esta classe NÃO faz ─────────────────────────────────────────────────────────
 * Não muda o resultado da busca: nem quem entra, nem em que ordem. A sugestão é um ADENDO no
 * `meta`. A hierarquia de `RelevanceLayer` é decisão de produto fechada, e um "você quis
 * dizer" que reordenasse a lista por conta própria a desfaria sem ninguém perceber.
 *
 * ── Quando aparece ────────────────────────────────────────────────────────────────────
 * Só quando o termo é UMA palavra que o vocabulário não resolve sozinho e que alcança mais
 * de um conceito por prefixo. `vet` (alias exato), `cardi`/`cardio` (prefixo de um conceito
 * só) e `Freitas` (não alcança nada) NÃO sugerem; `car` e `med` sugerem. São 35 os prefixos
 * que disparam no vocabulário inteiro — varridos e medidos. Termo de mais de uma palavra
 * fica de fora: "banho e tosa" já carrega contexto, e sugerir ali seria ruído.
 */
final class SearchSuggestionFinder
{
    /** Teto de sugestões devolvidas, como o contrato de fio define. */
    private const MAX_SUGGESTIONS = 3;

    /**
     * Teto de conceitos CONTADOS por busca. Ordenar por `total` exige contar todos os
     * candidatos antes de cortar, então o teto de consultas é separado do teto de sugestões.
     * O vocabulário produz no máximo 5 conceitos por prefixo; 4 cobre todos menos o extremo.
     */
    private const MAX_COUNTED_CONCEPTS = 4;

    /**
     * Orçamento de latência para as contagens que ainda NÃO estão em cache. Medição, não
     * precaução: varrer com cache limpo os 35 prefixos que disparam sugestão custava mediana
     * 135 ms, p90 445 ms e pior caso 671 ms — dobraria a latência da busca.
     *
     * O que está em cache entra SEMPRE (~0,2 ms, não consome orçamento); o que falta é
     * calculado até o orçamento acabar e o resto fica de fora DESTA resposta. Como cada
     * busca aquece ao menos uma contagem, a busca repetida converge para o conjunto
     * completo: sugestão de menos é degradação aceitável num adendo, total errado não seria.
     * Medido depois: frio mediana 49 ms, quente 0,7 ms.
     */
    private const COUNT_BUDGET_SECONDS = 0.040;

    public function __construct(
        private readonly SearchTextNormalizer $normalizer,
        private readonly SearchVocabulary $vocabulary,
        private readonly SearchConceptPresenter $presenter,
        private readonly ProfessionalMatchCounter $matchCounter,
    ) {}

    /**
     * @return list<SearchSuggestion>
     */
    public function for(SearchFiltersDTO $filters): array
    {
        $concepts = $this->ambiguousConceptsFor($filters->searchQuery);

        if ($concepts === []) {
            return [];
        }

        return $this->rank($this->countAll($concepts, $filters));
    }

    /**
     * @return list<SearchConcept>
     */
    private function ambiguousConceptsFor(?string $searchQuery): array
    {
        if ($searchQuery === null || ! $this->isSingleUnresolvedWord($searchQuery)) {
            return [];
        }

        $concepts = $this->vocabulary->conceptsMatchingPrefix($searchQuery);

        return count($concepts) > 1 ? array_slice($concepts, 0, self::MAX_COUNTED_CONCEPTS) : [];
    }

    /**
     * Uma palavra só, e nenhuma das duas resoluções do vocabulário dá conta dela:
     * `conceptFor()` é o casamento exato ("vet"); `prefixConceptFor()`, o prefixo que serve a
     * um conceito só ("cardi"). Quem passa pelos dois deixou o usuário sem resposta.
     */
    private function isSingleUnresolvedWord(string $searchQuery): bool
    {
        if (count($this->normalizer->tokenize($searchQuery)) !== 1) {
            return false;
        }

        return $this->vocabulary->conceptFor($searchQuery) === null
            && $this->vocabulary->prefixConceptFor($searchQuery) === null;
    }

    /**
     * Primeiro tudo que o cache já sabe (de graça), depois o que couber no orçamento. Contar
     * na ordem do vocabulário faria uma contagem cara na frente estourar o orçamento e
     * esconder três baratas atrás dela.
     *
     * @param  list<SearchConcept>  $concepts
     * @return list<SearchSuggestion>
     */
    private function countAll(array $concepts, SearchFiltersDTO $filters): array
    {
        $suggestions = [];
        $pending = [];

        foreach ($concepts as $concept) {
            $cached = $this->matchCounter->cachedCount($this->filtersFor($concept, $filters));

            if ($cached === null) {
                $pending[] = $concept;

                continue;
            }

            $suggestions[] = $this->suggestion($concept, $cached);
        }

        return [...$suggestions, ...$this->countWithinBudget($pending, $filters)];
    }

    /**
     * Conceito cuja contagem estourou o teto de tempo de banco é descartado — a exceção é
     * esperada e já foi registrada em log por quem a lançou. O "você quis dizer..." é um
     * adendo: sugestão a menos é aceitável, atrasar a lista que o usuário pediu não é.
     *
     * @param  list<SearchConcept>  $concepts
     * @return list<SearchSuggestion>
     */
    private function countWithinBudget(array $concepts, SearchFiltersDTO $filters): array
    {
        $deadline = microtime(true) + self::COUNT_BUDGET_SECONDS;
        $suggestions = [];

        foreach ($concepts as $concept) {
            $counted = $this->countOrSkip($concept, $filters);

            if ($counted !== null) {
                $suggestions[] = $counted;
            }

            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return $suggestions;
    }

    private function countOrSkip(SearchConcept $concept, SearchFiltersDTO $filters): ?SearchSuggestion
    {
        try {
            return $this->suggestion($concept, $this->matchCounter->count($this->filtersFor($concept, $filters)));
        } catch (SearchCountTimedOutException) {
            return null;
        }
    }

    private function filtersFor(SearchConcept $concept, SearchFiltersDTO $filters): SearchFiltersDTO
    {
        return $filters->withSearchQuery($this->presenter->termFor($concept));
    }

    private function suggestion(SearchConcept $concept, int $total): SearchSuggestion
    {
        return new SearchSuggestion(
            term: $this->presenter->termFor($concept),
            label: $this->presenter->labelFor($concept),
            total: $total,
        );
    }

    /**
     * Sugestão sem resultado é descartada antes de ordenar: levar o usuário de uma lista
     * imperfeita para uma tela vazia é piorar a busca, não corrigi-la.
     *
     * @param  list<SearchSuggestion>  $suggestions
     * @return list<SearchSuggestion>
     */
    private function rank(array $suggestions): array
    {
        $found = array_values(array_filter(
            $suggestions,
            static fn (SearchSuggestion $suggestion): bool => $suggestion->total > 0,
        ));

        usort($found, static fn (SearchSuggestion $first, SearchSuggestion $second): int => $second->total <=> $first->total);

        return array_slice($found, 0, self::MAX_SUGGESTIONS);
    }
}
