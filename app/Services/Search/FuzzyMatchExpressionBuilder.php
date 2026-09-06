<?php

namespace App\Services\Search;

/**
 * Constrói os fragmentos SQL da busca fuzzy (trigram/pg_trgm) usados por
 * `ProfessionalSearchService`. Extraído para classe própria (Fase 5 do plano de otimização)
 * porque a MESMA string de expressão precisa existir em dois lugares que não podem divergir:
 * aqui e nas migrations de índice GIN (`2026_09_06_000009_add_trigram_search_indexes.php`).
 * Se a expressão de uma das pontas mudar sem a outra, o Postgres para de casar o índice EM
 * SILÊNCIO — sem erro, só uma busca que volta a fazer seq scan.
 */
final class FuzzyMatchExpressionBuilder
{
    /**
     * `unaccent(text)` de 1 argumento é STABLE (resolve o dicionário via `search_path` em
     * runtime), então não pode ir em índice de expressão. Este wrapper (migration
     * 2026_09_06_000008) fixa o dicionário como `regdictionary`, o que o torna legitimamente
     * IMMUTABLE.
     */
    public const IMMUTABLE_UNACCENT_FUNCTION = 'public.immutable_unaccent';

    public function immutableUnaccent(string $column): string
    {
        return self::IMMUTABLE_UNACCENT_FUNCTION.'('.$column.')';
    }

    /**
     * Operador `%` do pg_trgm — o único que usa o índice GIN de expressão. A forma funcional
     * `similarity(x, y) > threshold` NUNCA usa índice, por mais que o índice exista. O
     * threshold usado pelo operador vem de `pg_trgm.similarity_threshold`, fixado a nível de
     * banco pela migration `2026_09_06_000010_set_trigram_similarity_threshold.php` — não por
     * request, que é frágil com conexão persistente.
     */
    public function fuzzyMatch(string $column): string
    {
        return $this->immutableUnaccent($column).' % '.$this->immutableUnaccent('?');
    }

    /**
     * `ILIKE '%termo%'` servido pelo MESMO índice GIN trigram do `fuzzyMatch()`. Só funciona
     * como index scan com 3+ caracteres não-curinga no termo — abaixo disso o pg_trgm não
     * extrai um trigrama completo (garantido por `SearchFiltersDTO`).
     */
    public function ilikeMatch(string $column): string
    {
        return $this->immutableUnaccent($column).' ILIKE '.$this->immutableUnaccent('?');
    }

    /**
     * `similarity()` puro, NÃO indexável — usar só no SELECT para calcular ranking. É
     * irrelevante não ter índice aqui: só roda nas poucas linhas já filtradas por
     * `fuzzyMatch()`/`ilikeMatch()`, nunca na tabela inteira.
     */
    public function similarity(string $column): string
    {
        return 'similarity('.$this->immutableUnaccent($column).', '.$this->immutableUnaccent('?').')';
    }
}
