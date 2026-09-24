<?php

namespace App\Http\Resources;

use App\DataTransferObjects\Location\ResolvedPlace;
use App\DataTransferObjects\Search\SearchResultMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Coleção da busca pública (`GET /api/public/search`).
 *
 * Acrescenta ao `meta` da paginação padrão o que só a busca sabe: as sugestões de termo
 * ("você quis dizer...") e o alcance real da paginação (`total_reachable`/`truncated`).
 *
 * ⚠️ `additional(['meta' => ...])` NÃO serve para isso com segurança: o Laravel funde o
 * `additional` com a informação de paginação usando `array_merge_recursive`
 * (`PaginatedResourceResponse::toResponse()`), e duas chaves escalares de mesmo nome viram
 * um ARRAY com os dois valores — `"total": [2991, 500]` — em vez de sobrescrever. O gancho
 * `paginationInformation()`, que só uma coleção própria tem, funde de forma previsível.
 *
 * `last_page` e `links` NÃO são tocados aqui: quem os corrige é
 * `App\Support\Pagination\ReachableLengthAwarePaginator`, na origem.
 */
class ProfessionalSearchCollection extends ResourceCollection
{
    /** @var class-string<ProfessionalSearchCardResource> */
    public $collects = ProfessionalSearchCardResource::class;

    private SearchResultMeta $searchMeta;

    private ?ResolvedPlace $origin = null;

    public function withSearchMeta(SearchResultMeta $searchMeta): self
    {
        $this->searchMeta = $searchMeta;

        return $this;
    }

    /**
     * Busca por CEP: devolve onde o CEP caiu ("Buscando perto de Centro, Poços de Caldas"),
     * para o frontend não ter que resolver o mesmo CEP de novo.
     */
    public function withOrigin(?ResolvedPlace $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    /**
     * Gancho do Laravel (`PaginatedResourceResponse::paginationInformation()`): recebe
     * `meta`/`links` já montados e devolve a versão final.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            ...$default,
            'meta' => [...($default['meta'] ?? []), ...$this->searchMeta->toArray(), ...$this->originMeta()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function originMeta(): array
    {
        if ($this->origin === null) {
            return [];
        }

        return ['origin' => ['source' => 'zip_code', ...$this->origin->toArray()]];
    }
}
