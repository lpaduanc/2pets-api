<?php

namespace App\Support\Pagination;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paginador que separa duas coisas que o Laravel trata como uma só: quantos resultados
 * CASARAM e quantos ele consegue ENTREGAR.
 *
 * ── O problema que isto resolve ───────────────────────────────────────────────────────
 * `ProfessionalSearchService` materializa no máximo `MAX_CACHED_IDS` (500) ids por busca,
 * mas o `LengthAwarePaginator` calcula `lastPage` a partir do total. Com `per_page=12` e
 * 2.991 resultados a API anunciava `last_page: 250`, `links.last` apontando para a página
 * 250 e uma lista de links numerados indo até a 250 — enquanto a página 42 já era a última
 * com conteúdo. Nada disso dava erro: a página 43 voltava `data: []`, HTTP 200.
 *
 * ── Por que aqui e não no `meta` da resposta ──────────────────────────────────────────
 * Porque `last_page` não é um número solto: `links.last`, `next_page_url`, `hasMorePages()`
 * e a lista de `links` numerados são TODOS derivados dele. Corrigir só o `last_page` no
 * resource deixaria os outros quatro anunciando a página 250 — a mesma mentira, escrita em
 * outro campo. Ensinando o paginador, tudo o que ele deriva sai coerente de graça.
 *
 * `total()` continua devolvendo o total real: "2.991 profissionais encontrados" é verdade e
 * é o número que o tutor quer ver. Quem publica a diferença é `SearchResultMeta`
 * (`total_reachable` + `truncated`).
 */
final class ReachableLengthAwarePaginator extends LengthAwarePaginator
{
    private int $reachableTotal = 0;

    /**
     * Quantos resultados a paginação consegue entregar de fato — na prática, quantos ids
     * foram materializados.
     */
    public function reachableUpTo(int $reachableTotal): self
    {
        $this->reachableTotal = $reachableTotal;
        $this->lastPage = max((int) ceil($reachableTotal / $this->perPage), 1);

        return $this;
    }

    public function reachableTotal(): int
    {
        return $this->reachableTotal;
    }
}
