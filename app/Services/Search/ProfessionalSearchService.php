<?php

namespace App\Services\Search;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ProfessionalSearchService
{
    public function __construct(
        private readonly GeoLocationService $geoLocationService
    ) {}

    public function search(SearchFiltersDTO $filters): LengthAwarePaginator
    {
        $query = $this->buildBaseQuery($filters);

        $this->applyLocationFilter($query, $filters);
        $this->applyProfessionalTypeFilter($query, $filters);
        $this->applyServiceCategoryFilter($query, $filters);
        $this->applyPriceRangeFilter($query, $filters);
        $this->applyRatingFilter($query, $filters);
        $this->applySearchQuery($query, $filters);
        $this->applySorting($query, $filters);

        return $query->paginate($filters->perPage);
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
            $query->selectRaw(
                "*, ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) / 1000 AS distance_km",
                [$filters->longitude, $filters->latitude]
            );
        } else {
            $query->select('*');
            $query->selectRaw('NULL as distance_km');
        }

        return $query;
    }

    private function applyLocationFilter(Builder $query, SearchFiltersDTO $filters): void
    {
        if (!$filters->hasLocation()) {
            return;
        }

        $query->having('distance_km', '<=', $filters->radiusKm);
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

        // Note: Requires reviews system from Phase 2
        // For now, we'll prepare the structure
        $query->whereHas('professional', function (Builder $subQuery) use ($filters) {
            $subQuery->whereRaw('COALESCE(average_rating, 0) >= ?', [$filters->minRating]);
        });
    }

    private function applySearchQuery(Builder $query, SearchFiltersDTO $filters): void
    {
        if ($filters->searchQuery === null) {
            return;
        }

        $searchTerm = '%' . $filters->searchQuery . '%';

        $query->where(function (Builder $subQuery) use ($searchTerm) {
            $subQuery->where('name', 'LIKE', $searchTerm)
                     ->orWhereHas('professional', function (Builder $professionalQuery) use ($searchTerm) {
                         $professionalQuery->where('business_name', 'LIKE', $searchTerm)
                                          ->orWhere('description', 'LIKE', $searchTerm);
                     });
        });
    }

    private function applySorting(Builder $query, SearchFiltersDTO $filters): void
    {
        match ($filters->sortBy) {
            'distance' => $this->sortByDistance($query, $filters),
            'rating' => $this->sortByRating($query),
            'price_low' => $this->sortByPriceLow($query),
            'price_high' => $this->sortByPriceHigh($query),
            default => $this->sortByDistance($query, $filters),
        };
    }

    private function sortByDistance(Builder $query, SearchFiltersDTO $filters): void
    {
        if (!$filters->hasLocation()) {
            $query->orderBy('name');
            return;
        }

        $query->orderBy('distance_km');
    }

    private function sortByRating(Builder $query): void
    {
        // Note: Requires reviews system from Phase 2
        $query->orderByRaw('COALESCE(average_rating, 0) DESC');
    }

    private function sortByPriceLow(Builder $query): void
    {
        $query->orderBy('name'); // Placeholder until we aggregate service prices
    }

    private function sortByPriceHigh(Builder $query): void
    {
        $query->orderBy('name', 'desc'); // Placeholder until we aggregate service prices
    }
}
