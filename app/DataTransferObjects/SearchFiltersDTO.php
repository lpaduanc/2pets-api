<?php

namespace App\DataTransferObjects;

use Illuminate\Validation\ValidationException;

final readonly class SearchFiltersDTO
{
    /**
     * Comprimento mínimo do termo de busca fuzzy. Abaixo disso o pg_trgm não consegue
     * extrair um trigrama completo da palavra, e o operador `%`/ILIKE usado por
     * `ProfessionalSearchService` cai para varredura sequencial mesmo com o índice GIN da
     * Fase 5 (migration `2026_09_06_000009_add_trigram_search_indexes.php`).
     */
    private const MINIMUM_SEARCH_TERM_LENGTH = 3;

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
        public bool $availableNow = false,
        public int $page = 1,
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
            searchQuery: self::normalizeSearchQuery($data['query'] ?? null),
            sortBy: $data['sort_by'] ?? 'distance',
            perPage: isset($data['per_page']) ? (int) $data['per_page'] : 15,
            availableNow: filter_var($data['available_now'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // Page passa a viver no DTO (nao mais lido via `request()` de dentro do
            // service) para que `ProfessionalSearchService`/`ProfessionalSearchCache`
            // nao dependam de estado HTTP global — quebra testável e reuso fora de um
            // request real.
            page: isset($data['page']) ? max(1, (int) $data['page']) : 1,
        );
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Termo vazio/só-espaço vira `null` (sem filtro de busca). Termo preenchido mas menor
     * que `MINIMUM_SEARCH_TERM_LENGTH` é rejeitado explicitamente: deixá-lo passar geraria
     * um `%`/ILIKE contra o índice GIN trigram que o pg_trgm não consegue casar com um
     * trigrama completo — seq scan garantido sobre a tabela inteira.
     */
    private static function normalizeSearchQuery(?string $rawQuery): ?string
    {
        $trimmedQuery = trim((string) $rawQuery);

        if ($trimmedQuery === '') {
            return null;
        }

        if (mb_strlen($trimmedQuery) < self::MINIMUM_SEARCH_TERM_LENGTH) {
            throw ValidationException::withMessages([
                'query' => [sprintf(
                    'O termo de busca precisa ter pelo menos %d caracteres.',
                    self::MINIMUM_SEARCH_TERM_LENGTH
                )],
            ]);
        }

        return $trimmedQuery;
    }

    public function hasPriceRange(): bool
    {
        return $this->minPrice !== null || $this->maxPrice !== null;
    }
}
