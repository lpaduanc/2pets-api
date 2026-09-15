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

    private const DEFAULT_SORT = 'distance';

    /**
     * Com termo de busca, ordenar por distância enterra o resultado certo: o profissional
     * que casa exatamente com "cardiologia" fica atrás de qualquer um mais perto que casou
     * de raspão. Quem digita um termo está procurando aquilo, não o vizinho mais próximo.
     */
    private const DEFAULT_SORT_WITH_QUERY = 'relevance';

    /**
     * `page` vive no DTO (não é lido via `request()` de dentro do service) para que
     * `ProfessionalSearchService`/`ProfessionalSearchCache` não dependam de estado HTTP
     * global — testável e reusável fora de um request real.
     */
    public function __construct(
        public ?float $latitude,
        public ?float $longitude,
        public ?int $radiusKm,
        /** @var list<string> */
        public array $professionalTypes,
        /** @var list<string> */
        public array $serviceCategories,
        public ?float $minPrice,
        public ?float $maxPrice,
        public ?float $minRating,
        public ?string $searchQuery,
        public string $sortBy = self::DEFAULT_SORT,
        public int $perPage = 15,
        public bool $availableNow = false,
        public int $page = 1,
        /** @var list<string> */
        public array $specialties = [],
        /** @var list<string> */
        public array $species = [],
    ) {}

    public static function fromRequest(array $data): self
    {
        $searchQuery = self::normalizeSearchQuery($data['query'] ?? null);

        return new self(
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            radiusKm: isset($data['radius_km']) ? (int) $data['radius_km'] : 50,
            professionalTypes: self::normalizeFilterList($data['professional_type'] ?? null),
            serviceCategories: self::normalizeFilterList($data['service_category'] ?? null),
            minPrice: isset($data['min_price']) ? (float) $data['min_price'] : null,
            maxPrice: isset($data['max_price']) ? (float) $data['max_price'] : null,
            minRating: isset($data['min_rating']) ? (float) $data['min_rating'] : null,
            searchQuery: $searchQuery,
            sortBy: $data['sort_by'] ?? self::defaultSortFor($searchQuery),
            perPage: isset($data['per_page']) ? (int) $data['per_page'] : 15,
            availableNow: filter_var($data['available_now'] ?? false, FILTER_VALIDATE_BOOLEAN),
            page: isset($data['page']) ? max(1, (int) $data['page']) : 1,
            specialties: self::normalizeFilterList($data['specialty'] ?? null),
            species: self::normalizeFilterList($data['species'] ?? null),
        );
    }

    private static function defaultSortFor(?string $searchQuery): string
    {
        return $searchQuery === null ? self::DEFAULT_SORT : self::DEFAULT_SORT_WITH_QUERY;
    }

    /**
     * Dimensão multivalorada: escalar legado (`?species=dog`) e array
     * (`?species[]=dog&species[]=cat`) chegam à MESMA `list<string>`. Lista vazia é filtro
     * ausente — nunca `null` contra `[]` como dois estados diferentes.
     *
     * A ordenação é deliberada e não é cosmética: `ProfessionalSearchCache` monta a chave a
     * partir destes campos, e sem forma canônica `[vet,clinic]` e `[clinic,vet]` viram DUAS
     * entradas de cache para a MESMA busca (OR é comutativo) — o acerto cairia pela metade a
     * cada valor extra que o usuário marcasse.
     *
     * @return list<string>
     */
    private static function normalizeFilterList(mixed $rawValue): array
    {
        $values = is_array($rawValue) ? $rawValue : [$rawValue];

        $normalized = array_filter(
            array_map(self::normalizeFilterValue(...), $values),
            static fn (?string $value): bool => $value !== null,
        );

        $unique = array_values(array_unique($normalized));
        sort($unique);

        return $unique;
    }

    /**
     * Filtro em branco é filtro ausente. Sem isso, `?specialty=` (vazio, que é o que um
     * `<select>` sem escolha manda) viraria um filtro por string vazia e devolveria zero.
     */
    private static function normalizeFilterValue(mixed $rawValue): ?string
    {
        if (! is_scalar($rawValue)) {
            return null;
        }

        $trimmed = trim((string) $rawValue);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A MESMA busca com outro termo — todos os filtros, o geo e a ordenação preservados.
     *
     * Existe para o "você quis dizer...": o `total` prometido em cada sugestão só é honesto
     * se for contado sob exatamente os mesmos filtros da busca atual. Reconstruir o DTO à
     * mão no chamador significaria esquecer um campo no dia em que um filtro novo nascer —
     * e o sintoma seria um número errado na sugestão, sem erro em lugar nenhum.
     */
    public function withSearchQuery(string $searchQuery): self
    {
        return new self(
            latitude: $this->latitude,
            longitude: $this->longitude,
            radiusKm: $this->radiusKm,
            professionalTypes: $this->professionalTypes,
            serviceCategories: $this->serviceCategories,
            minPrice: $this->minPrice,
            maxPrice: $this->maxPrice,
            minRating: $this->minRating,
            searchQuery: $searchQuery,
            sortBy: $this->sortBy,
            perPage: $this->perPage,
            availableNow: $this->availableNow,
            page: $this->page,
            specialties: $this->specialties,
            species: $this->species,
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
}
