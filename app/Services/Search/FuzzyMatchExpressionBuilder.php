<?php

namespace App\Services\Search;

use App\Enums\Search\WordSimilarityMode;

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

    /**
     * Pré-filtro INDEXÁVEL por similaridade de palavra. É o que resolve o problema real da
     * busca do 2pets: `%` compara as strings INTEIRAS, então "cardiologia" contra
     * "Dra. Carolina Mendes, especialista em Cardiologia" dá 0,289 (não casa nem com
     * limiar frouxo), enquanto `%>` procura o melhor TRECHO contínuo e dá 1,000. Medido,
     * não estimado.
     *
     * A coluna fica à ESQUERDA porque é assim — e só assim — que o Postgres resolve o
     * predicado como `Index Cond` do GIN trigram; com a agulha à esquerda o mesmo
     * predicado vira `Filter` e o índice deixa de ser usado, em silêncio.
     *
     * Um binding: a agulha.
     */
    public function wordSimilarityMatch(string $column, WordSimilarityMode $mode): string
    {
        return $this->immutableUnaccent($column).' '.$mode->operator().' '.$this->immutableUnaccent('?');
    }

    /**
     * Refino NÃO indexável que aplica o limiar exato do contexto, sempre em AND com
     * `wordSimilarityMatch()`. O operador sozinho usaria o limiar global do banco
     * (`pg_trgm.word_similarity_threshold`), o que amarraria a precisão da busca a uma
     * configuração de servidor — mudar o GUC por qualquer outro motivo mudaria o resultado
     * da busca sem uma linha de código alterada. Aqui o operador serve para usar o índice
     * e esta expressão decide quem fica; ela roda só sobre o que o índice já devolveu.
     *
     * Dois bindings, nesta ordem: a agulha e o limiar.
     */
    public function wordSimilarityAtLeast(string $column, WordSimilarityMode $mode): string
    {
        return $mode->function().'('.$this->immutableUnaccent('?').', '.$this->immutableUnaccent($column).') >= ?';
    }

    /**
     * `word_similarity()` puro para ranking — mesma lógica do `similarity()` acima: sem
     * índice, mas só roda nas linhas que já passaram pelo filtro.
     *
     * Um binding: a agulha.
     */
    public function wordSimilarity(string $column): string
    {
        return 'word_similarity('.$this->immutableUnaccent('?').', '.$this->immutableUnaccent($column).')';
    }

    /**
     * Casamento por PREFIXO DE PALAVRA, para termo curto (até 3 caracteres, que é o mínimo
     * que `SearchFiltersDTO` deixa passar).
     *
     * Motivo, relatado pelo dono do produto: buscar "car" trouxe "Deluxe Pet Care". O
     * resultado em si não está errado — com três letras, casar "Pet Care" é a leitura
     * possível —, mas ele saía de similaridade de trigrama solta, que com agulha curta é
     * quase sorteio: "car" também flertava com "Carla", "Oscar" e "Descarte". Tratado como
     * prefixo de palavra, o comportamento fica PREVISÍVEL: "car" casa palavra que COMEÇA com
     * "car" e mais nada. O usuário consegue prever o resultado antes de apertar enter, que é
     * o que "busca boa" significa para termo curto.
     *
     * `\m` é início de palavra no regex do Postgres (o mesmo já usado em
     * `ProfessionalAttributeFilter`). O predicado é INDEXÁVEL pelo mesmo GIN trigram: o
     * `gin_trgm_ops` declara `~` e `~*` entre seus operadores, e o pg_trgm extrai os
     * trigramas do regex para usá-los como `Index Cond`. Verificado com EXPLAIN nesta base:
     * `Bitmap Index Scan on idx_users_name_unaccent_trgm_live`, `Index Cond:
     * (immutable_unaccent(name) ~* '\mmar')`. Só funciona com 3+ caracteres (um trigrama
     * completo), que é exatamente o piso de `SearchFiltersDTO`.
     *
     * ⚠️ `~*` e NÃO `~`. `immutable_unaccent()` remove acento mas NÃO muda a caixa — com o
     * operador sensível a maiúsculas, `'\mmar'` não encontrava "Mariana" nem "Marcelo".
     * Falha silenciosa clássica: a query roda, o índice é usado, e o resultado só vem
     * incompleto. Pego com EXPLAIN ANALYZE (143 linhas com `~*`, 0 com `~`), não por leitura.
     *
     * A agulha é segura como regex sem escape porque `SearchTextNormalizer` já reduziu o
     * termo a `[a-z0-9 ]` — nenhum metacaractere sobrevive à normalização.
     *
     * Um binding: a agulha.
     */
    public function wordPrefixMatch(string $column): string
    {
        return $this->immutableUnaccent($column)." ~* ('\\m' || ".$this->immutableUnaccent('?').')';
    }
}
