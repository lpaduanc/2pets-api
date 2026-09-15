<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\FieldMatch;
use App\Enums\Search\SearchField;
use App\Enums\Search\WordSimilarityMode;

/**
 * Decide COMO comparar uma agulha com um campo, e devolve o predicado com os bindings.
 *
 * Três regimes, por tamanho da agulha — e a escolha é feita num lugar só porque filtro e
 * ranking PRECISAM concordar: se o filtro aceitasse por prefixo e o ranking pontuasse por
 * similaridade de trigrama, a linha entraria no resultado com uma nota calculada por um
 * critério diferente do que a deixou entrar.
 *
 * | tamanho | regime | porquê |
 * |---|---|---|
 * | ≤ 3 | prefixo de palavra (`~ '\m…'`) | trigrama solto com agulha curta é quase sorteio |
 * | 4 | `strict_word_similarity` (`%>>`) | exige fronteira de palavra; senão "ana" traz "Banana" |
 * | ≥ 5 | `word_similarity` (`%>`) | acha a palavra dentro do campo longo |
 *
 * Os dois últimos regimes e seus limiares foram calibrados por medição contra pares reais do
 * catálogo — ver `WordSimilarityMode` e `SearchField::threshold()`.
 */
final class SearchFieldMatcher
{
    /**
     * Piso de `SearchFiltersDTO::MINIMUM_SEARCH_TERM_LENGTH`: 3 é a menor agulha que chega
     * aqui, e também o mínimo para o pg_trgm extrair um trigrama completo do regex.
     */
    private const PREFIX_MAX_LENGTH = 3;

    public function __construct(private readonly FuzzyMatchExpressionBuilder $expressions) {}

    /**
     * Predicado de FILTRO: a parte indexável mais o refino que aplica o limiar do contexto.
     */
    public function filter(SearchField $field, string $needle): FieldMatch
    {
        if ($this->isShort($needle)) {
            return $this->prefixMatch($field, $needle);
        }

        $mode = WordSimilarityMode::forNeedle($needle);

        return new FieldMatch(
            '('.$this->expressions->wordSimilarityMatch($field->value, $mode)
            .' AND '.$this->expressions->wordSimilarityAtLeast($field->value, $mode).')',
            [$needle, $needle, $field->threshold($mode)],
        );
    }

    /**
     * Só a parte INDEXÁVEL, sem o refino de limiar — para quem já está dentro de um contexto
     * filtrado e só precisa saber "casou ou não" (o ranking por tier).
     */
    public function indexed(SearchField $field, string $needle): FieldMatch
    {
        if ($this->isShort($needle)) {
            return $this->prefixMatch($field, $needle);
        }

        return new FieldMatch(
            $this->expressions->wordSimilarityMatch($field->value, WordSimilarityMode::forNeedle($needle)),
            [$needle],
        );
    }

    /**
     * Expressão de PONTUAÇÃO do campo (peso × evidência), no MESMO regime de comparação que
     * o filtro usou.
     *
     * Está aqui, e não no ranking, por um bug real pego na verificação: o filtro tratava
     * agulha curta como prefixo de palavra enquanto o ranking continuava pontuando por
     * `word_similarity` da frase inteira. Para "car", a similaridade de trigrama contra
     * "Hospedagem Animal Jardins" é ~0 — ou seja, TODA linha que entrava por prefixo saía com
     * nota zero e a lista voltava em ordem arbitrária. O filtro dizia uma coisa e o ranking
     * media outra.
     *
     * Com agulha curta a pontuação vira tier (casou o prefixo ou não): não existe gradação
     * significativa em três letras, e fingir que existe é o que produzia ordem aleatória.
     */
    public function score(SearchField $field, string $needle): FieldMatch
    {
        $weight = (float) $field->relevanceWeight();

        if ($this->isShort($needle)) {
            return new FieldMatch(
                sprintf('CASE WHEN %s THEN %.2F ELSE 0 END', $this->expressions->wordPrefixMatch($field->value), $weight),
                [$needle],
            );
        }

        // `%.2F` (maiúsculo) e não `%.2f`: a forma minúscula respeita o locale e geraria
        // `0,95` em pt-BR, que o Postgres lê como erro de sintaxe.
        return new FieldMatch(
            sprintf('%.2F * %s', $weight, $this->expressions->wordSimilarity($field->value)),
            [$needle],
        );
    }

    private function prefixMatch(SearchField $field, string $needle): FieldMatch
    {
        return new FieldMatch($this->expressions->wordPrefixMatch($field->value), [$needle]);
    }

    private function isShort(string $needle): bool
    {
        return mb_strlen($needle) <= self::PREFIX_MAX_LENGTH;
    }
}
