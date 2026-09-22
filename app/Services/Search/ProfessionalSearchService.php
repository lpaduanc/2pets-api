<?php

namespace App\Services\Search;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Models\Professional;
use App\Models\User;
use App\Services\Organization\TeamSizeQuery;
use App\Support\Pagination\ReachableLengthAwarePaginator;
use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class ProfessionalSearchService
{
    /**
     * Teto de ids guardados por entrada de cache — a paginacao real fatia este array
     * em PHP (`paginateFromCachedIds()`), entao 500 cobre confortavelmente qualquer
     * combinacao razoavel de `page`/`per_page` sem precisar re-executar a query.
     *
     * ⚠️ É TAMBÉM o teto do que a paginação por offset consegue ENTREGAR: com `per_page=12`
     * a página 42 é a última com conteúdo, mesmo quando o total casado é 2.991. Quem carrega
     * essa distinção até a resposta é `ReachableLengthAwarePaginator`, montado em
     * `paginateFromCachedIds()`.
     */
    private const MAX_CACHED_IDS = 500;

    public function __construct(
        private readonly GeoLocationService $geoLocationService,
        private readonly ProfessionalSearchCache $professionalSearchCache,
        private readonly ProfessionalTextSearchQuery $professionalTextSearchQuery,
        private readonly ProfessionalAttributeFilter $professionalAttributeFilter,
    ) {}

    /**
     * Standard paginated search (offset-based) — used for initial load and when total count is needed.
     */
    public function search(SearchFiltersDTO $filters): LengthAwarePaginator
    {
        // "Disponível agora" depende do minuto atual — bypass do cache garante consistência.
        if ($filters->availableNow) {
            return $this->filteredQuery($filters)
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
        $query = $this->filteredQuery($filters, 'users.id');

        return $filters->searchQuery === null
            ? $this->fetchIdsWithCountQuery($query)
            : $this->fetchIdsWithWindowCount($query);
    }

    /**
     * Sem termo de busca o filtro é barato e a lista de ids sai de um índice já ordenado,
     * podendo parar no LIMIT. Medido: 91 ms para o COUNT de 33 mil linhas e 8 ms para os
     * 500 ids. Duas queries baratas ganham de uma só que obrigue o Postgres a materializar
     * o resultado inteiro — por isso este caminho NÃO usa a janela de `fetchIdsWithWindowCount`.
     *
     * @return array{ids: list<int>, total: int}
     */
    private function fetchIdsWithCountQuery(Builder $query): array
    {
        $total = $query->toBase()->getCountForPagination();

        $ids = $query->limit(self::MAX_CACHED_IDS)
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        return ['ids' => $ids, 'total' => $total];
    }

    /**
     * Com termo de busca, o filtro fuzzy É a parte cara — e rodá-lo DUAS vezes (uma para o
     * COUNT da paginação, outra para os ids) era o desperdício mais caro da busca.
     * `count(*) OVER ()` devolve o total exato na mesma passada.
     *
     * Medido (~45 mil profissionais, cache limpo, duas passadas × janela):
     * "veterinario" + geo 20km 1.988 ms → 1.378 ms; "clinica veterinaria" 6.224 ms →
     * 4.114 ms. O ganho existe porque a ordenação por relevância já obriga a materializar
     * todas as linhas candidatas: a segunda passada não economizava nada, só repetia.
     *
     * `toBase()` evita hidratar 500 models e disparar o eager loading de
     * `professional`/`services` — quem hidrata é `hydrateInOrder()`, e só a página pedida.
     *
     * @return array{ids: list<int>, total: int}
     */
    private function fetchIdsWithWindowCount(Builder $query): array
    {
        $rows = $query->selectRaw('count(*) OVER () AS matched_total')
            ->toBase()
            ->limit(self::MAX_CACHED_IDS)
            ->get();

        return [
            'ids' => $rows->pluck('id')->map(fn (int|string $id): int => (int) $id)->all(),
            'total' => $rows->isEmpty() ? 0 : (int) $rows->first()->matched_total,
        ];
    }

    /**
     * Fatia os ids cacheados para a pagina pedida e hidrata os models numa UNICA
     * query (`hydrateInOrder`). `$filters->page` vem do DTO, nunca de `request()`
     * lido de dentro do service.
     *
     * O alcançável é `count($cachedResult['ids'])` — o número REAL de ids materializados —
     * e não `min($total, MAX_CACHED_IDS)`. As duas expressões valem o mesmo hoje, mas a
     * primeira é o fato e a segunda é uma reprodução da regra que o produziu; se o teto
     * mudar de lugar, só a segunda passa a mentir.
     *
     * @param  array{ids: list<int>, total: int}  $cachedResult
     */
    private function paginateFromCachedIds(array $cachedResult, SearchFiltersDTO $filters): LengthAwarePaginator
    {
        $page = max(1, $filters->page);
        $offset = ($page - 1) * $filters->perPage;
        $pageIds = array_slice($cachedResult['ids'], $offset, $filters->perPage);

        return (new ReachableLengthAwarePaginator(
            $this->hydrateInOrder($pageIds, $filters),
            $cachedResult['total'],
            $filters->perPage,
            $page,
        ))->reachableUpTo(count($cachedResult['ids']));
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
        TeamSizeQuery::applyTo($query);

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
        $query = $this->filteredQuery($filters);

        // Cursor pagination doesn't work with computed columns (distance_km)
        // So we use it only when sorting by non-computed fields
        if ($filters->hasLocation() && $filters->sortBy === 'distance') {
            return $query->paginate($filters->perPage);
        }

        // `relevance` ordena por `search_relevance`, coluna calculada no SELECT: o cursor
        // precisaria carregar o valor dela para montar a página seguinte, o que não existe.
        // Passou a importar de verdade agora que `relevance` é o sort PADRÃO quando há termo
        // de busca — antes só chegava aqui quem pedisse explicitamente.
        if ($filters->sortBy === 'relevance') {
            return $query->paginate($filters->perPage);
        }

        return $query->cursorPaginate($filters->perPage);
    }

    /**
     * A query com TODOS os filtros aplicados e já ordenada. Pública porque
     * `ProfessionalMatchCounter` precisa exatamente dela para contar sem paginar — contar com
     * uma query montada em outro lugar seria contar outra coisa.
     *
     * `$baseProjection` existe por causa do custo do SORT, medido com EXPLAIN (ANALYZE,
     * BUFFERS): com `users.*` cada linha candidata carrega ~4 KB pelo `top-N heapsort`, e
     * um termo genérico que casa 33 mil profissionais empurra mais de 100 MB por um sort
     * que no fim devolve 500 ids. `fetchSearchIds()` não precisa de uma coluna sequer além
     * do id — quem hidrata o model é `hydrateInOrder()`, depois, já com a página fatiada.
     */
    public function filteredQuery(SearchFiltersDTO $filters, string $baseProjection = 'users.*'): Builder
    {
        $query = $this->buildBaseQuery($filters, $baseProjection);

        $this->applyLocationFilter($query, $filters);
        $this->applyProfessionalFilters($query, $filters);
        $this->applyServiceFilters($query, $filters);
        $this->applyAvailableNowFilter($query, $filters);
        $this->applySearchQuery($query, $filters);
        $this->applySorting($query, $filters);

        return $query;
    }

    private function buildBaseQuery(SearchFiltersDTO $filters, string $baseProjection = 'users.*'): Builder
    {
        $query = User::query()
            ->visibleProfessional()
            ->with(['professional', 'professional.services']);

        $this->applyDistanceSelect($query, $filters, $baseProjection);
        TeamSizeQuery::applyTo($query);

        return $query;
    }

    /**
     * SELECT compartilhado entre a query de filtragem (`buildBaseQuery`, usada tanto
     * para o caminho sem cache quanto para calcular os ids cacheados) e a hidratacao
     * por ids do cache (`hydrateInOrder`). Extraido para as duas nunca divergirem —
     * duas implementacoes da mesma expressao PostGIS uma hora ficam dessincronizadas.
     */
    private function applyDistanceSelect(Builder $query, SearchFiltersDTO $filters, string $baseProjection = 'users.*'): void
    {
        if (! $filters->hasLocation()) {
            $query->select($baseProjection);
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
            "{$baseProjection}, ({$distance['sql']}) / 1000 AS distance_km",
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

    /**
     * TODOS os filtros sobre `professionals` (tipo, nota mínima, especialidade, espécie) vão
     * numa ÚNICA subquery não correlacionada. As duas decisões — subquery só, e não
     * correlacionada — são de plano, medidas nesta base:
     *
     * 1. **Não correlacionada.** Com `whereHas`/EXISTS, combinado com `ST_DWithin` mais a
     *    busca textual, o planner estima a cardinalidade externa em 1 e escolhe um semi-join
     *    cujo lado interno é `Seq Scan` — medido: 3.882 loops, 38 milhões de linhas, 33 s.
     * 2. **Uma subquery só.** Um `whereIn` por filtro devolve o mesmo problema por outra
     *    porta: o Postgres encadeia um semi-join por filtro e o último recai em `Seq Scan`.
     *    Medido com "termo + tipo + nota + geo": **42,8 s** separados contra **137 ms**
     *    juntos. Cada filtro isolado sempre esteve rápido — é a COMBINAÇÃO que quebrava, e
     *    é por isso que um teste de filtro por vez não pega isso.
     */
    private function applyProfessionalFilters(Builder $query, SearchFiltersDTO $filters): void
    {
        $conditions = $this->professionalConditions($filters);

        if ($conditions === []) {
            return;
        }

        $query->whereIn('users.id', function (QueryBuilder $sub) use ($conditions) {
            $sub->select('professionals.user_id')
                ->from('professionals')
                ->whereNull('professionals.deleted_at');

            $this->applyAll($sub, $conditions);
        });
    }

    /**
     * `average_rating` é comparada DIRETO contra a coluna. Antes era
     * `COALESCE(average_rating, 0) >= ?`: o COALESCE era morto (a coluna é
     * `NOT NULL DEFAULT 0`) e, envolvendo a coluna numa função, tornava o predicado não
     * indexável — foi este filtro que produziu o `Seq Scan` citado acima.
     *
     * Multi-seleção (`?professional_type[]=vet&professional_type[]=clinic`) é OR DENTRO da
     * dimensão e AND entre dimensões, e o OR nunca vira uma condição por valor: tipo vira um
     * `whereIn` (forma indexável pelo índice composto
     * `professionals_professional_type_user_id_index`), especialidade vira um grupo aninhado
     * só e espécie vira um único `jsonb_exists_any`. Tudo continua dentro da MESMA subquery.
     *
     * @return list<Closure(QueryBuilder): void>
     */
    private function professionalConditions(SearchFiltersDTO $filters): array
    {
        $conditions = [];

        if ($filters->professionalTypes !== []) {
            $conditions[] = fn (QueryBuilder $sub) => $sub->whereIn('professionals.professional_type', $filters->professionalTypes);
        }

        if ($filters->minRating !== null) {
            $conditions[] = fn (QueryBuilder $sub) => $sub->where('professionals.average_rating', '>=', $filters->minRating);
        }

        if ($filters->specialties !== []) {
            $conditions[] = $this->professionalAttributeFilter->specialtyCondition($filters->specialties);
        }

        if ($filters->species !== []) {
            $conditions[] = $this->professionalAttributeFilter->speciesCondition($filters->species);
        }

        return $conditions;
    }

    /**
     * Categoria e faixa de preço juntas, pelo mesmo motivo de `applyProfessionalFilters()`.
     * `services.professional_id` referencia `users.id` diretamente, então a subquery não
     * passa por `professionals` — um nível de EXISTS a menos do que o
     * `whereHas('professional.services')` anterior.
     */
    private function applyServiceFilters(Builder $query, SearchFiltersDTO $filters): void
    {
        $conditions = $this->serviceConditions($filters);

        if ($conditions === []) {
            return;
        }

        $query->whereIn('users.id', function (QueryBuilder $sub) use ($conditions) {
            $sub->select('services.professional_id')
                ->from('services')
                ->whereRaw('services.active = true')
                ->whereNull('services.deleted_at');

            $this->applyAll($sub, $conditions);
        });
    }

    /**
     * @return list<Closure(QueryBuilder): void>
     */
    private function serviceConditions(SearchFiltersDTO $filters): array
    {
        $conditions = [];

        if ($filters->serviceCategories !== []) {
            $conditions[] = fn (QueryBuilder $sub) => $sub->whereIn('services.category', $filters->serviceCategories);
        }

        if ($filters->minPrice !== null) {
            $conditions[] = fn (QueryBuilder $sub) => $sub->where('services.price', '>=', $filters->minPrice);
        }

        if ($filters->maxPrice !== null) {
            $conditions[] = fn (QueryBuilder $sub) => $sub->where('services.price', '<=', $filters->maxPrice);
        }

        return $conditions;
    }

    /**
     * @param  list<Closure(QueryBuilder): void>  $conditions
     */
    private function applyAll(QueryBuilder $sub, array $conditions): void
    {
        foreach ($conditions as $condition) {
            $condition($sub);
        }
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
     * Busca textual. Toda a construcao do WHERE fuzzy e do ranking mora em
     * `ProfessionalTextSearchQuery` — inclusive a licao de arquitetura medida na Fase 5
     * (EXISTS/whereHas, nunca LEFT JOIN, sob pena de o planner abandonar os indices GIN),
     * que esta documentada la no docblock da classe.
     */
    private function applySearchQuery(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->searchQuery === null) {
            return;
        }

        $this->professionalTextSearchQuery->apply($query, $filters->searchQuery);
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
