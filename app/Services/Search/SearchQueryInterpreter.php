<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\InterpretedSearchQuery;
use App\DataTransferObjects\Search\SearchUnit;

/**
 * Traduz o que o usuário digitou para o que a busca precisa exigir.
 *
 * Ordem de resolução por unidade, da mais precisa para a menos:
 * 1. janela multi-palavra no vocabulário ("banho e tosa" antes de "banho");
 * 2. palavra isolada no vocabulário ("cardiologista" → cardiologia);
 * 3. palavra isolada que é PREFIXO de um alias ("cardio" → cardiologia);
 * 4. palavra isolada parecida o bastante com um alias ("cargiolista");
 * 5. a palavra crua, exigida como está.
 *
 * O passo 5 é o que garante "sem devaneios": termo que não virou conceito NÃO é descartado
 * nem relaxado — ele continua sendo obrigatório, e uma busca por "xyzabc" devolve vazio em
 * vez de devolver a base inteira.
 */
final class SearchQueryInterpreter
{
    public function __construct(
        private readonly SearchTextNormalizer $normalizer,
        private readonly SearchVocabulary $vocabulary,
    ) {}

    public function interpret(string $rawQuery): InterpretedSearchQuery
    {
        $phrase = $this->normalizer->normalize($rawQuery);
        $tokens = $this->normalizer->tokenize($rawQuery);

        if ($tokens === []) {
            return InterpretedSearchQuery::make($phrase, $this->fallbackUnits($phrase));
        }

        return InterpretedSearchQuery::make($phrase, $this->buildUnits($tokens));
    }

    /**
     * Frase só de stopwords ("para o meu") não tem unidade nenhuma; em vez de virar busca
     * sem filtro (que devolveria todo mundo), a frase inteira vira uma exigência única.
     *
     * @return list<SearchUnit>
     */
    private function fallbackUnits(string $phrase): array
    {
        return $phrase === '' ? [] : [SearchUnit::fromTerm($phrase)];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<SearchUnit>
     */
    private function buildUnits(array $tokens): array
    {
        $units = [];
        $position = 0;

        while ($position < count($tokens)) {
            [$unit, $consumed] = $this->unitAt($tokens, $position);
            $units[] = $unit;
            $position += $consumed;
        }

        return $units;
    }

    /**
     * @param  list<string>  $tokens
     * @return array{0: SearchUnit, 1: int}
     */
    private function unitAt(array $tokens, int $position): array
    {
        $remaining = count($tokens) - $position;
        $widest = min($this->vocabulary->longestAliasWordCount(), $remaining);

        for ($width = $widest; $width >= 1; $width--) {
            $window = implode(' ', array_slice($tokens, $position, $width));
            $concept = $this->vocabulary->conceptFor($window);

            if ($concept !== null) {
                return [SearchUnit::fromConcept($window, $concept), $width];
            }
        }

        return [$this->singleTokenUnit($tokens[$position]), 1];
    }

    /**
     * Ordem: prefixo antes de typo. "cardio" é uma palavra INCOMPLETA, não uma palavra
     * errada — e os dois critérios medem coisas diferentes. Resolver o prefixo primeiro
     * também evita que uma abreviação caia por acaso num alias parecido.
     */
    private function singleTokenUnit(string $token): SearchUnit
    {
        $concept = $this->vocabulary->prefixConceptFor($token)
            ?? $this->vocabulary->closestConceptFor($token);

        return $concept === null
            ? SearchUnit::fromTerm($token)
            : SearchUnit::fromConcept($token, $concept);
    }
}
