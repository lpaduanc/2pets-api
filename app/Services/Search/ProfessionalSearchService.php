<?php

namespace App\Services\Search;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorImplementation;
use Illuminate\Support\Facades\DB;

final class ProfessionalSearchService
{
    /**
     * Teto de ids guardados por entrada de cache — a paginacao real fatia este array
     * em PHP (`paginateFromCachedIds()`), entao 500 cobre confortavelmente qualquer
     * combinacao razoavel de `page`/`per_page` sem precisar re-executar a query.
     */
    private const MAX_CACHED_IDS = 500;

    public function __construct(
        private readonly GeoLocationService $geoLocationService,
        private readonly FuzzyMatchExpressionBuilder $fuzzyMatchExpressionBuilder,
        private readonly ProfessionalSearchCache $professionalSearchCache,
    ) {}

    /**
     * Standard paginated search (offset-based) — used for initial load and when total count is needed.
     */
    public function search(SearchFiltersDTO $filters): LengthAwarePaginator
    {
        // "Disponível agora" depende do minuto atual — bypass do cache garante consistência.
        if ($filters->availableNow) {
            return $this->buildFilteredQuery($filters)
                ->paginate($filters->perPage, ['*'], 'page', $filters->page);
        }

        $cachedResult = $this->professionalSearchCache->remember(
            $filters,
            fn () => $this->fetchSearchIds($filters),
        );

        return $this->paginateFromCachedIds($cachedResult, $filters);
    }

    /**
     * Roda a query completa (todos os filtros + ordenacao), mas so extrai os ids —
     * nunca os models. E o unico dado que `ProfessionalSearchCache` guarda: payload
     * minusculo, e o Redis nunca fica com um `professional.services` congelado.
     *
     * @return array{ids: list<int>, total: int}
     */
    private function fetchSearchIds(SearchFiltersDTO $filters): array
    {
        $query = $this->buildFilteredQuery($filters);

        $total = $query->toBase()->getCountForPagination();

        $ids = $query->limit(self::MAX_CACHED_IDS)
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        return ['ids' => $ids, 'total' => $total];
    }

    /**
     * Fatia os ids cacheados para a pagina pedida e hidrata os models numa UNICA
     * query (`hydrateInOrder`). `$filters->page` vem do DTO, nunca de `request()`
     * lido de dentro do service.
     *
     * @param  array{ids: list<int>, total: int}  $cachedResult
     */
    private function paginateFromCachedIds(array $cachedResult, SearchFiltersDTO $filters): LengthAwarePaginator
    {
        $page = max(1, $filters->page);
        $offset = ($page - 1) * $filters->perPage;
        $pageIds = array_slice($cachedResult['ids'], $offset, $filters->perPage);

        return new LengthAwarePaginatorImplementation(
            $this->hydrateInOrder($pageIds, $filters),
            $cachedResult['total'],
            $filters->perPage,
            $page,
        );
    }

    /**
     * Hidrata os models sempre a partir do Postgres — so os ids passam pelo cache,
     * nunca o registro em si, entao nenhum dado de model fica desatualizado.
     * `array_position` preserva a ordem de relevancia decidida pela query original
     * (distancia/relevancia/preco/avaliacao), com o array de ids como UM UNICO
     * binding (`pgBigintArrayLiteral`) — nunca interpolado na string SQL.
     * `applyDistanceSelect` roda de novo aqui com a coordenada EXATA de `$filters`
     * (nao a coordenada arredondada da grade de cache), entao `distance_km` sai
     * correto mesmo quando o usuario nao esta no centro da celula.
     */
    private function hydrateInOrder(array $ids, SearchFiltersDTO $filters): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        $query = User::query()
            ->whereIn('users.id', $ids)
            ->with(['professional', 'professional.services']);

        $this->applyDistanceSelect($query, $filters);

        return $query
            ->orderByRaw('array_position(?::bigint[], users.id)', [$this->pgBigintArrayLiteral($ids)])
            ->get();
    }

    /**
     * Formata ids como literal de array do Postgres (`{1,2,3}`) para uso como UM
     * UNICO binding em `array_position(...)` — nunca interpolacao de string na query.
     *
     * @param  list<int>  $ids
     */
    private function pgBigintArrayLiteral(array $ids): string
    {
        return '{'.implode(',', array_map('intval', $ids)).'}';
    }

    /**
     * Cursor-based pagination — efficient for infinite scroll.
     * Falls back to offset pagination when geo-sorting is active (computed columns don't work with cursors).
     */
    public function searchCursor(SearchFiltersDTO $filters): CursorPaginator|LengthAwarePaginator
    {
        $query = $this->buildFilteredQuery($filters);

        // Cursor pagination doesn't work with computed columns (distance_km)
        // So we use it only when sorting by non-computed fields
        if ($filters->hasLocation() && $filters->sortBy === 'distance') {
            return $query->paginate($filters->perPage);
        }

        return $query->cursorPaginate($filters->perPage);
    }

    private function buildFilteredQuery(SearchFiltersDTO $filters): Builder
    {
        $query = $this->buildBaseQuery($filters);

        $this->applyLocationFilter($query, $filters);
        $this->applyProfessionalTypeFilter($query, $filters);
        $this->applyServiceCategoryFilter($query, $filters);
        $this->applyPriceRangeFilter($query, $filters);
        $this->applyRatingFilter($query, $filters);
        $this->applyAvailableNowFilter($query, $filters);
        $this->applySearchQuery($query, $filters);
        $this->applySorting($query, $filters);

        return $query;
    }

    private function buildBaseQuery(SearchFiltersDTO $filters): Builder
    {
        $query = User::query()
            ->visibleProfessional()
            ->with(['professional', 'professional.services']);

        $this->applyDistanceSelect($query, $filters);

        return $query;
    }

    /**
     * SELECT compartilhado entre a query de filtragem (`buildBaseQuery`, usada tanto
     * para o caminho sem cache quanto para calcular os ids cacheados) e a hidratacao
     * por ids do cache (`hydrateInOrder`). Extraido para as duas nunca divergirem —
     * duas implementacoes da mesma expressao PostGIS uma hora ficam dessincronizadas.
     */
    private function applyDistanceSelect(Builder $query, SearchFiltersDTO $filters): void
    {
        if (! $filters->hasLocation()) {
            $query->select('users.*');
            $query->selectRaw('NULL::double precision AS distance_km');

            return;
        }

        $distance = $this->geoLocationService->distanceExpression(
            'users.location',
            $filters->latitude,
            $filters->longitude
        );

        // ST_Distance com geography retorna metros, dividimos por 1000 para km
        $query->selectRaw(
            "users.*, ({$distance['sql']}) / 1000 AS distance_km",
            $distance['bindings']
        );
    }

    /**
     * Filtra por raio usando ST_DWithin que aproveita o indice GIST na coluna location.
     * Diferente do HAVING com ST_Distance_Sphere do MySQL, ST_DWithin e um filtro
     * no WHERE que usa o indice espacial, resultando em performance muito superior.
     */
    private function applyLocationFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if (! $filters->hasLocation()) {
            return;
        }

        $dWithin = $this->geoLocationService->dWithinExpression(
            'users.location',
            $filters->latitude,
            $filters->longitude,
            $filters->radiusKm
        );

        $query->whereRaw($dWithin['sql'], $dWithin['bindings']);
    }

    private function applyProfessionalTypeFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->professionalType === null) {
            return;
        }

        $query->whereHas('professional', function (Builder $subQuery) use ($filters) {
            $subQuery->where('professional_type', $filters->professionalType);
        });
    }

    private function applyServiceCategoryFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->serviceCategory === null) {
            return;
        }

        $query->whereHas('professional.services', function (Builder $subQuery) use ($filters) {
            $subQuery->where('category', $filters->serviceCategory)
                ->where('active', true);
        });
    }

    private function applyPriceRangeFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if (! $filters->hasPriceRange()) {
            return;
        }

        $query->whereHas('professional.services', function (Builder $subQuery) use ($filters) {
            if ($filters->minPrice !== null) {
                $subQuery->where('price', '>=', $filters->minPrice);
            }

            if ($filters->maxPrice !== null) {
                $subQuery->where('price', '<=', $filters->maxPrice);
            }
        });
    }

    private function applyRatingFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->minRating === null) {
            return;
        }

        $query->whereHas('professional', function (Builder $subQuery) use ($filters) {
            $subQuery->whereRaw('COALESCE(average_rating, 0) >= ?', [$filters->minRating]);
        });
    }

    /**
     * Filtro "Disponível agora":
     * - Exige uma linha em `availabilities` cobrindo o dia da semana + horário atuais, com is_active=true.
     *   Usa o índice composto (professional_id, day_of_week, is_active).
     * - Exclui profissionais com `blocked_times` ativo cobrindo o instante atual (férias, feriado, pausa).
     *
     * Ambas as cláusulas usam whereExists/whereNotExists para aproveitar índices e evitar join na lista.
     *
     * NOTA: Profissionais que só têm working_days/opening_hours (campo legado em users) e não
     * possuem registros em `availabilities` não aparecerão neste filtro. Isso é intencional no beta
     * — `availabilities` é a fonte canônica usada pelo AvailabilityService/booking.
     */
    private function applyAvailableNowFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if (! $filters->availableNow) {
            return;
        }

        $now = now();
        $dayOfWeek = $now->dayOfWeek; // Carbon: 0=domingo..6=sábado (idêntico à migration)
        $currentTime = $now->format('H:i:s');
        $currentDateTime = $now->format('Y-m-d H:i:s');

        $query->whereExists(function ($sub) use ($dayOfWeek, $currentTime) {
            $sub->select(DB::raw(1))
                ->from('availabilities')
                ->whereColumn('availabilities.professional_id', 'users.id')
                ->where('availabilities.day_of_week', $dayOfWeek)
                ->where('availabilities.is_active', true)
                ->where('availabilities.start_time', '<=', $currentTime)
                ->where('availabilities.end_time', '>=', $currentTime);
        });

        $query->whereNotExists(function ($sub) use ($currentDateTime) {
            $sub->select(DB::raw(1))
                ->from('blocked_times')
                ->whereColumn('blocked_times.professional_id', 'users.id')
                ->where('blocked_times.start_datetime', '<=', $currentDateTime)
                ->where('blocked_times.end_datetime', '>=', $currentDateTime);
        });
    }

    /**
     * Busca textual usando pg_trgm (operador `%`) + unaccent para lidar com acentuacao PT-BR.
     *
     * - `%` (FuzzyMatchExpressionBuilder::fuzzyMatch) usa o indice GIN de expressao criado na
     *   Fase 5 (migration 2026_09_06_000009) — diferente da forma funcional
     *   `similarity(...) > x`, que nunca usa indice.
     * - ILIKE serve como fallback para substrings exatas e e servido pelo MESMO indice GIN.
     * - O filtro em `business_name`/`description` usa `whereHas` (EXISTS), NAO um LEFT JOIN.
     *   Motivo medido com EXPLAIN, nao teorico: o Postgres so consegue combinar (BitmapOr)
     *   multiplos indices GIN quando todas as condicoes do OR pertencem a UMA UNICA tabela
     *   sendo varrida. Um OR que mistura `users.name` com uma coluna trazida por LEFT JOIN de
     *   `professionals` obriga o planner a materializar o JOIN inteiro antes de filtrar —
     *   nenhum dos dois indices GIN trigram e usado (plano vira Hash Join + Filter
     *   sequencial). Pior: combinado com `ST_DWithin` (busca com localizacao), um LEFT JOIN
     *   ali fez o planner subestimar a cardinalidade a ponto de escolher `Seq Scan` em
     *   `professionals` dentro de um Nested Loop SEM indice — um plano que trava por minutos
     *   com o volume de benchmark (~200k linhas), documentado em
     *   `.claude/agent-memory/backend-specialist/busca-fuzzy-fase5.md`. Com `whereHas`, cada lado do OR
     *   e uma condicao independente sobre UMA tabela: Postgres escolhe
     *   `idx_users_name_unaccent_trgm` para o lado de `users` e
     *   `idx_professionals_business_name_unaccent_trgm`/`idx_professionals_description_unaccent_trgm`
     *   (via `BitmapOr`, dentro do SubPlan do EXISTS) para o lado de `professionals`.
     * - `similarity()` puro (nao indexavel) so aparece no SELECT para ranking, via subquery
     *   escalar correlacionada (nao LEFT JOIN, pelo mesmo motivo acima) — roda apenas nas
     *   linhas que chegam ao resultado final.
     */
    private function applySearchQuery(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->searchQuery === null) {
            return;
        }

        $searchTerm = $filters->searchQuery;
        $ilikeTerm = '%'.$searchTerm.'%';

        $this->applyFuzzyMatchFilter($query, $searchTerm, $ilikeTerm);
        $this->applySearchRelevanceSelect($query, $searchTerm);
    }

    private function applyFuzzyMatchFilter(Builder $query, string $searchTerm, string $ilikeTerm): void
    {
        $expression = $this->fuzzyMatchExpressionBuilder;

        $query->where(function (Builder $subQuery) use ($expression, $searchTerm, $ilikeTerm) {
            $subQuery->whereRaw($expression->fuzzyMatch('users.name'), [$searchTerm])
                ->orWhereRaw($expression->ilikeMatch('users.name'), [$ilikeTerm])
                ->orWhereHas('professional', function (Builder $professionalQuery) use ($expression, $searchTerm, $ilikeTerm) {
                    $professionalQuery->where(function (Builder $q) use ($expression, $searchTerm, $ilikeTerm) {
                        $q->whereRaw($expression->fuzzyMatch('business_name'), [$searchTerm])
                            ->orWhereRaw($expression->ilikeMatch('business_name'), [$ilikeTerm])
                            ->orWhereRaw($expression->ilikeMatch('description'), [$ilikeTerm]);
                    });
                });
        });
    }

    /**
     * `business_name` vem de uma subquery escalar correlacionada (`pp.user_id = users.id`),
     * não de um LEFT JOIN — ver o porquê no docblock de `applySearchQuery()`. Usa
     * `idx_professionals_user_id` (Fase 4) por linha; como não participa do ORDER BY em
     * a maioria dos `sortBy` (só quando `sortBy = relevance`), o Postgres adia o cálculo
     * para depois do LIMIT sempre que possível — medido via EXPLAIN, não suposto.
     */
    private function applySearchRelevanceSelect(Builder $query, string $searchTerm): void
    {
        $nameSimilarity = $this->fuzzyMatchExpressionBuilder->similarity('users.name');
        $businessNameSimilarity = $this->fuzzyMatchExpressionBuilder->similarity('pp.business_name');

        $query->selectRaw(
            "GREATEST({$nameSimilarity}, COALESCE((SELECT MAX({$businessNameSimilarity}) FROM professionals pp WHERE pp.user_id = users.id), 0)) AS search_relevance",
            [$searchTerm, $searchTerm]
        );
    }

    private function applySorting(Builder $query, SearchFiltersDTO $filters): void
    {
        match ($filters->sortBy) {
            'distance' => $this->sortByDistance($query, $filters),
            'rating' => $this->sortByRating($query),
            'relevance' => $this->sortByRelevance($query, $filters),
            'price_low' => $this->sortByPriceLow($query),
            'price_high' => $this->sortByPriceHigh($query),
            default => $this->sortByDistance($query, $filters),
        };
    }

    private function sortByDistance(Builder $query, SearchFiltersDTO $filters): void
    {
        if (! $filters->hasLocation()) {
            $query->orderBy('users.name');

            return;
        }

        // Ordena pela coluna distance_km calculada no SELECT via PostGIS
        $query->orderByRaw('distance_km ASC NULLS LAST');
    }

    /**
     * Sort by rating descending. Subquery escalar correlacionada — não LEFT JOIN — pelo
     * mesmo motivo documentado em `applySearchQuery()`: um LEFT JOIN em `professionals`
     * combinado com o filtro geográfico + a busca fuzzy confunde a estimativa de
     * cardinalidade do planner e pode gerar um `Seq Scan` sem índice dentro de um Nested
     * Loop (medido via EXPLAIN, não teórico). `addSelect` mantém `professional_rating`
     * disponível no resultado — necessário para `searchCursor()` montar o próximo cursor.
     */
    private function sortByRating(Builder $query): void
    {
        $query->addSelect([
            'professional_rating' => Professional::selectRaw('COALESCE(professionals.average_rating, 0)')
                ->whereColumn('professionals.user_id', 'users.id')
                ->limit(1),
        ])->orderByRaw('professional_rating DESC');
    }

    private function sortByRelevance(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->searchQuery !== null) {
            $query->orderByRaw('search_relevance DESC');
        } else {
            $this->sortByDistance($query, $filters);
        }
    }

    /**
     * Sort by lowest service price ascending. `services.professional_id` referencia
     * `users.id` (não `professionals.id`), por isso a correlação é direta contra `users.id`.
     * `LEFT JOIN LATERAL` (não subquery correlacionada) faz MIN e MAX saírem da MESMA
     * passada em `services` — ver `professionalPriceStatsQuery()`. `addSelect` só projeta a
     * coluna já calculada pelo join; é necessário para `searchCursor()` montar o cursor da
     * página seguinte.
     */
    private function sortByPriceLow(Builder $query): void
    {
        $query->leftJoinLateral($this->professionalPriceStatsQuery(), 'service_price_stats')
            ->addSelect('service_price_stats.min_price')
            ->orderByRaw('service_price_stats.min_price ASC NULLS LAST');
    }

    /**
     * Sort by highest service price descending. Mesma passada em `services` que
     * `sortByPriceLow()` — ver `professionalPriceStatsQuery()`.
     */
    private function sortByPriceHigh(Builder $query): void
    {
        $query->leftJoinLateral($this->professionalPriceStatsQuery(), 'service_price_stats')
            ->addSelect('service_price_stats.max_price')
            ->orderByRaw('service_price_stats.max_price DESC NULLS LAST');
    }

    /**
     * MIN e MAX de `services.price` numa única varredura por usuário candidato, via
     * `LEFT JOIN LATERAL` (correlação em `whereColumn`). Antes eram duas subqueries
     * correlacionadas independentes (uma para MIN, outra para MAX) quando ambos os sentidos
     * de ordenação eram exercitados. Usa o índice `idx_services_professional_active_price`
     * (professional_id, price) WHERE active, criado na Fase 4.
     */
    private function professionalPriceStatsQuery(): QueryBuilder
    {
        // Raw query builder on `services` doesn't get Eloquent's soft-delete global scope for
        // free — without this, a deleted service's price still counts toward MIN/MAX sorting.
        return DB::table('services')
            ->selectRaw('MIN(services.price) AS min_price, MAX(services.price) AS max_price')
            ->whereColumn('services.professional_id', 'users.id')
            ->where('services.active', true)
            ->whereNull('services.deleted_at');
    }
}
