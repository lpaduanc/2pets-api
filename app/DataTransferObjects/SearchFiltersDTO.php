<?php

namespace App\DataTransferObjects;

final readonly class SearchFiltersDTO
{
    public function __construct(
        public ?float $latitude,
        public ?float $longitude,
        public ?int $radiusKm,
        public ?string $professionalType,
        public ?string $serviceCategory,
        public ?float $minPrice,
        public ?float $maxPrice,
        public ?float $minRating,
        public ?string $searchQuery,
        public string $sortBy = 'distance',
        public int $perPage = 15,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            radiusKm: isset($data['radius_km']) ? (int) $data['radius_km'] : 50,
            professionalType: $data['professional_type'] ?? null,
            serviceCategory: $data['service_category'] ?? null,
            minPrice: isset($data['min_price']) ? (float) $data['min_price'] : null,
            maxPrice: isset($data['max_price']) ? (float) $data['max_price'] : null,
            minRating: isset($data['min_rating']) ? (float) $data['min_rating'] : null,
            searchQuery: $data['query'] ?? null,
            sortBy: $data['sort_by'] ?? 'distance',
            perPage: isset($data['per_page']) ? (int) $data['per_page'] : 15,
        );
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function hasPriceRange(): bool
    {
        return $this->minPrice !== null || $this->maxPrice !== null;
    }
}
