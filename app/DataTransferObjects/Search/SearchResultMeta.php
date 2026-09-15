<?php

namespace App\DataTransferObjects\Search;

/**
 * O que a busca pública acrescenta ao `meta` da resposta, além da paginação padrão do
 * Laravel: as sugestões de termo e — a parte que conserta uma incoerência de contrato — até
 * onde a paginação por offset consegue realmente ir.
 *
 * ── O contrato que estava sendo quebrado ──────────────────────────────────────────────
 * `ProfessionalSearchService::MAX_CACHED_IDS` limita a 500 os ids materializados, mas
 * `last_page` era calculado sobre o total REAL. Com `per_page=12` e 2.991 resultados a API
 * anunciava `last_page: 250` e devolvia página vazia a partir da 42ª — sem erro, sem aviso.
 * Cliente que confia no `meta` (o comportamento correto de um cliente) batia no vazio.
 *
 * A correção tem duas metades. `last_page`, `links.last`, `next_page_url` e a lista de links
 * numerados passaram a sair certos na origem, porque quem os deriva é
 * `ReachableLengthAwarePaginator` — corrigir só o `last_page` aqui deixaria os outros quatro
 * anunciando a página 250. Esta classe publica a metade que o paginador não tem onde
 * guardar:
 *
 * - `total_reachable` — quantos resultados a paginação entrega (500);
 * - `truncated` — se `total` e `total_reachable` divergem.
 *
 * `total` continua real, porque "2.991 profissionais encontrados" é informação verdadeira e
 * é o número que o tutor quer ver.
 *
 * Quando não há teto (busca com `available_now`, que não passa pelo cache, e o caminho de
 * cursor) nada disso é publicado — anunciar teto onde não existe é mentir na outra direção.
 */
final readonly class SearchResultMeta
{
    /**
     * @param  list<SearchSuggestion>  $suggestions
     */
    private function __construct(
        public array $suggestions,
        public ?int $totalReachable,
        public bool $truncated,
    ) {}

    /**
     * @param  list<SearchSuggestion>  $suggestions
     */
    public static function unbounded(array $suggestions): self
    {
        return new self($suggestions, null, false);
    }

    /**
     * @param  list<SearchSuggestion>  $suggestions
     */
    public static function bounded(array $suggestions, int $total, int $totalReachable): self
    {
        return new self(
            suggestions: $suggestions,
            totalReachable: $totalReachable,
            truncated: $totalReachable < $total,
        );
    }

    /**
     * `suggestions` sai SEMPRE, mesmo vazio: forma estável de resposta é o que permite ao
     * cliente escrever `meta.suggestions.length` sem guarda. Chave que aparece e some
     * conforme o termo digitado é fonte garantida de `undefined` no frontend.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = [
            'suggestions' => array_map(
                static fn (SearchSuggestion $suggestion): array => $suggestion->toArray(),
                $this->suggestions,
            ),
        ];

        if ($this->totalReachable === null) {
            return $meta;
        }

        return [
            ...$meta,
            'total_reachable' => $this->totalReachable,
            'truncated' => $this->truncated,
        ];
    }
}
