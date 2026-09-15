<?php

namespace App\Enums\Search;

/**
 * Qual das duas famílias de "similaridade de palavra" do pg_trgm usar.
 *
 * Existe para manter operador e função SEMPRE em par: `%>` com `word_similarity()` e `%>>`
 * com `strict_word_similarity()`. Misturar os dois (operador de um, função de refino do
 * outro) não dá erro nenhum — só muda o critério de corte em silêncio, que é o tipo de bug
 * que ninguém acha depois.
 *
 * Diferença medida, não teórica (`immutable_unaccent` nos dois lados):
 * - `word_similarity('cat', 'Catarina Silva')` = 0,750 — casa prefixo dentro da palavra;
 * - `strict_word_similarity('cat', 'Catarina Silva')` = 0,300 — exige fronteira de palavra.
 * Por isso termo curto usa STRICT: com WORD, "ana" traria "Banana Pet Shop".
 */
enum WordSimilarityMode: string
{
    case WORD = 'word';
    case STRICT_WORD = 'strict_word';

    /**
     * Até 4 caracteres o termo tem pouca informação e `word_similarity` casa qualquer
     * prefixo dentro de outra palavra. Daí para baixo, exige-se fronteira de palavra. A
     * regra mora no enum porque filtro e ranking precisam concordar: se um usasse `%>` e o
     * outro `%>>` para a mesma agulha, a linha entraria no resultado com uma pontuação
     * calculada por um critério diferente do que a deixou entrar.
     */
    private const SHORT_TERM_LENGTH = 4;

    public static function forNeedle(string $needle): self
    {
        return mb_strlen($needle) <= self::SHORT_TERM_LENGTH ? self::STRICT_WORD : self::WORD;
    }

    /**
     * Operador indexável pelo GIN trigram. A COLUNA fica à ESQUERDA e a agulha à direita —
     * invertido em relação à função, e a única ordem que o Postgres resolve como
     * `Index Cond` (verificado com EXPLAIN: com a agulha à esquerda o mesmo predicado cai
     * para `Filter` e o índice não é usado).
     */
    public function operator(): string
    {
        return match ($this) {
            self::WORD => '%>',
            self::STRICT_WORD => '%>>',
        };
    }

    /**
     * Função de refino, usada para aplicar o limiar POR CONTEXTO. Aqui a agulha vem
     * PRIMEIRO: `word_similarity(agulha, campo)` mede a agulha dentro do campo.
     */
    public function function(): string
    {
        return match ($this) {
            self::WORD => 'word_similarity',
            self::STRICT_WORD => 'strict_word_similarity',
        };
    }
}
