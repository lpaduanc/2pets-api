<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\SearchResultMeta;
use App\DataTransferObjects\SearchFiltersDTO;
use App\Support\Pagination\ReachableLengthAwarePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Compõe o `meta` da busca pública: sugestões de termo + até onde a paginação alcança.
 *
 * Os dois métodos públicos existem porque os dois caminhos de paginação têm garantias
 * diferentes, e a diferença é de contrato, não de implementação:
 *
 * - **offset** (`search()`) passa pelo cache de ids e por isso tem teto;
 * - **cursor** (`searchCursor()`) vai direto ao banco, não tem teto e nem sequer expõe
 *   `total` — não há o que anunciar.
 *
 * Um único método com `instanceof` do paginador escondendo os dois casos seria mais curto e
 * menos honesto: o chamador SABE qual caminho tomou, e é ele quem deve dizer.
 */
final class SearchResultMetaBuilder
{
    public function __construct(private readonly SearchSuggestionFinder $suggestionFinder) {}

    /**
     * O teto vem do PRÓPRIO paginador, e não de uma constante consultada de fora. Quem
     * truncou sabe quanto truncou; reproduzir a regra aqui criaria dois lugares para manter
     * o mesmo número. `available_now` devolve um paginador comum (não passa pelo cache, logo
     * não tem teto) e cai corretamente no caminho sem limite.
     */
    public function forPage(SearchFiltersDTO $filters, LengthAwarePaginator $results): SearchResultMeta
    {
        $suggestions = $this->suggestionFinder->for($filters);

        if (! $results instanceof ReachableLengthAwarePaginator) {
            return SearchResultMeta::unbounded($suggestions);
        }

        return SearchResultMeta::bounded($suggestions, $results->total(), $results->reachableTotal());
    }

    public function forCursor(SearchFiltersDTO $filters): SearchResultMeta
    {
        return SearchResultMeta::unbounded($this->suggestionFinder->for($filters));
    }
}
