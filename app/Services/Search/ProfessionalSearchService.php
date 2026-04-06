<?php

namespace App\Services\Search;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

final class ProfessionalSearchService
{
    private const CACHE_TTL_SECONDS = 300; // 5 minutos
    private const SIMILARITY_THRESHOLD = 0.2;

    public function __construct(
        private readonly GeoLocationService $geoLocationService
    ) {}

    /**
     * Standard paginated search (offset-based) — used for initial load and when total count is needed.
     */
    public function search(SearchFiltersDTO $filters): LengthAwarePaginator
    {
        $cacheKey = $this->buildCacheKey($filters);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($filters) {
            $query = $this->buildFilteredQuery($filters);
            return $query->paginate($filters->perPage);
        });
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
        $this->applySearchQuery($query, $filters);
        $this->applySorting($query, $filters);

        return $query;
    }

    private function buildBaseQuery(SearchFiltersDTO $filters): Builder
    {
        $query = User::query()
            ->where('role', 'professional')
            ->where('profile_completed', true)
            ->where('registration_status', 'approved')
            ->where('is_suspended', false)
            ->with(['professional', 'professional.services']);

        if ($filters->hasLocation()) {
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
        } else {
            $query->select('users.*');
            $query->selectRaw('NULL::double precision AS distance_km');
        }

        return $query;
    }

    /**
     * Filtra por raio usando ST_DWithin que aproveita o indice GIST na coluna location.
     * Diferente do HAVING com ST_Distance_Sphere do MySQL, ST_DWithin e um filtro
     * no WHERE que usa o indice espacial, resultando em performance muito superior.
     */
    private function applyLocationFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if (!$filters->hasLocation()) {
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
        if (!$filters->hasPriceRange()) {
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
     * Busca textual usando pg_trgm (similarity) + unaccent para lidar com acentuacao PT-BR.
     *
     * - similarity() com threshold 0.2 captura erros de digitacao (fuzzy match)
     * - unaccent() normaliza acentos (ex: "clinica" encontra "Clinica")
     * - ILIKE serve como fallback para substrings exatas
     * - Resultados de fuzzy search recebem score de similaridade para ordenacao
     */
    private function applySearchQuery(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->searchQuery === null) {
            return;
        }

        $searchTerm = $filters->searchQuery;
        $ilikeTerm = '%' . $searchTerm . '%';

        $query->where(function (Builder $subQuery) use ($searchTerm, $ilikeTerm) {
            // Fuzzy match no nome do usuario com unaccent
            $subQuery->whereRaw(
                'similarity(unaccent(users.name), unaccent(?)) > ?',
                [$searchTerm, self::SIMILARITY_THRESHOLD]
            )
            // Fuzzy match no business_name do profissional (usa indice GIN gin_trgm_ops)
            ->orWhereHas('professional', function (Builder $professionalQuery) use ($searchTerm, $ilikeTerm) {
                $professionalQuery->where(function (Builder $q) use ($searchTerm, $ilikeTerm) {
                    $q->whereRaw(
                        'similarity(unaccent(business_name), unaccent(?)) > ?',
                        [$searchTerm, self::SIMILARITY_THRESHOLD]
                    )
                    ->orWhereRaw('unaccent(business_name) ILIKE unaccent(?)', [$ilikeTerm])
                    ->orWhereRaw('unaccent(description) ILIKE unaccent(?)', [$ilikeTerm]);
                });
            })
            // Fallback: ILIKE no nome do usuario com unaccent
            ->orWhereRaw('unaccent(users.name) ILIKE unaccent(?)', [$ilikeTerm]);
        });

        // Adiciona score de similaridade para ordenacao por relevancia
        $query->selectRaw(
            'GREATEST(
                similarity(unaccent(users.name), unaccent(?)),
                COALESCE((
                    SELECT MAX(similarity(unaccent(p.business_name), unaccent(?)))
                    FROM professionals p
                    WHERE p.user_id = users.id
                ), 0)
            ) AS search_relevance',
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
        if (!$filters->hasLocation()) {
            $query->orderBy('users.name');
            return;
        }

        // Ordena pela coluna distance_km calculada no SELECT via PostGIS
        $query->orderByRaw('distance_km ASC NULLS LAST');
    }

    /**
     * Sort by rating descending. The average_rating column lives on the professionals table,
     * so we use a correlated subquery to fetch it from the correct table.
     */
    private function sortByRating(Builder $query): void
    {
        $query->addSelect([
            'professional_rating' => \App\Models\Professional::selectRaw('COALESCE(professionals.average_rating, 0)')
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
     * Sort by lowest service price ascending. The services.professional_id column
     * references the user_id (not professionals.id), so we correlate directly
     * against users.id.
     */
    private function sortByPriceLow(Builder $query): void
    {
        $query->addSelect([
            'min_price' => \App\Models\Service::selectRaw('MIN(services.price)')
                ->whereColumn('services.professional_id', 'users.id')
                ->where('services.active', true),
        ])->orderByRaw('min_price ASC NULLS LAST');
    }

    /**
     * Sort by highest service price descending. The services.professional_id column
     * references the user_id (not professionals.id), so we correlate directly
     * against users.id.
     */
    private function sortByPriceHigh(Builder $query): void
    {
        $query->addSelect([
            'max_price' => \App\Models\Service::selectRaw('MAX(services.price)')
                ->whereColumn('services.professional_id', 'users.id')
                ->where('services.active', true),
        ])->orderByRaw('max_price DESC NULLS LAST');
    }

    /**
     * Gera uma chave de cache deterministica baseada nos filtros de busca.
     * Usa hash MD5 para manter a chave curta e consistente.
     */
    private function buildCacheKey(SearchFiltersDTO $filters): string
    {
        $keyData = [
            'lat' => $filters->latitude ? round($filters->latitude, 4) : null,
            'lng' => $filters->longitude ? round($filters->longitude, 4) : null,
            'radius' => $filters->radiusKm,
            'type' => $filters->professionalType,
            'category' => $filters->serviceCategory,
            'min_price' => $filters->minPrice,
            'max_price' => $filters->maxPrice,
            'min_rating' => $filters->minRating,
            'query' => $filters->searchQuery,
            'sort' => $filters->sortBy,
            'per_page' => $filters->perPage,
            'page' => request()->input('page', 1),
        ];

        $hash = md5(serialize($keyData));

        return "professional_search:{$hash}";
    }
}
