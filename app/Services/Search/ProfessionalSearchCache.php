<?php

namespace App\Services\Search;

use App\DataTransferObjects\SearchFiltersDTO;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache do RESULTADO da busca de profissionais — Fase 9 do plano de otimizacao.
 *
 * Chave por grade geografica adaptativa ao raio (nunca lat/lng exata) + contador de
 * versao (nunca TTL curto isolado, nunca KEYS/SCAN, nunca Cache::tags — caros ou
 * perigosos no Redis). `ProfessionalSearchService` injeta esta classe e so entrega a
 * ela o resultado ja calculado (ids + total); esta classe nunca decide QUAL
 * profissional aparece, so ONDE e POR QUANTO TEMPO guardar.
 *
 * Invalidacao: `App\Observers\Search\ProfessionalSearchCacheObserver`, registrado em
 * `AppServiceProvider::boot()` para `User` (role professional), `Professional` e
 * `Service`, incrementa `self::VERSION_CACHE_KEY` a cada save/delete/restore
 * relevante. Um unico `Cache::increment` torna toda chave `v{N}:*` anterior
 * inalcancavel instantaneamente — sem varrer o Redis.
 */
final class ProfessionalSearchCache
{
    public const VERSION_CACHE_KEY = 'professional_search:version';

    /**
     * Teto de seguranca, NAO o mecanismo principal de invalidacao (esse e o contador
     * de versao acima). Cobre a janela entre uma escrita que nao dispara evento
     * Eloquent (ex.: `Professional::where(...)->update()` em massa, usado pela
     * aprovacao de CRMV) e o proximo `Cache::increment` de alguma outra escrita.
     */
    private const TTL_SECONDS = 60;

    /** Raio <= 5km: celula de ~1,1km de lado. */
    private const GRID_CELL_SMALL_DEGREES = 0.01;

    /** Raio <= 25km: celula de ~5,5km de lado. */
    private const GRID_CELL_MEDIUM_DEGREES = 0.05;

    /** Raio > 25km (ou sem raio definido): celula de ~11km de lado. */
    private const GRID_CELL_LARGE_DEGREES = 0.10;

    private const SMALL_RADIUS_LIMIT_KM = 5;

    private const MEDIUM_RADIUS_LIMIT_KM = 25;

    /**
     * @return array{ids: list<int>, total: int}
     */
    public function remember(SearchFiltersDTO $filters, Closure $resultResolver): array
    {
        return Cache::remember($this->buildCacheKey($filters), self::TTL_SECONDS, $resultResolver);
    }

    private function buildCacheKey(SearchFiltersDTO $filters): string
    {
        $keyData = [
            'lat' => $this->snapToGrid($filters->latitude, $filters->radiusKm),
            'lng' => $this->snapToGrid($filters->longitude, $filters->radiusKm),
            'radius' => $filters->radiusKm,
            'type' => $filters->professionalType,
            'category' => $filters->serviceCategory,
            'min_price' => $filters->minPrice,
            'max_price' => $filters->maxPrice,
            'min_rating' => $filters->minRating,
            'query' => $filters->searchQuery,
            'sort' => $filters->sortBy,
        ];

        $hash = md5(serialize($keyData));

        return 'professional_search:v'.$this->currentVersion().":{$hash}";
    }

    /**
     * Arredonda a coordenada para o canto da celula da grade (`floor`), proporcional
     * ao raio de busca — e por isso que o resultado cacheado NUNCA guarda
     * `distance_km`: um hit calculado a partir do canto da celula erraria a distancia
     * real em ate meia celula. `ProfessionalSearchService` recalcula `distance_km` na
     * hidratacao, sempre com a coordenada EXATA de `$filters`, nunca com o valor
     * arredondado aqui (que existe so para compor a chave).
     */
    private function snapToGrid(?float $coordinate, ?int $radiusKm): ?float
    {
        if ($coordinate === null) {
            return null;
        }

        $cellSize = $this->gridCellSizeDegrees($radiusKm);

        return floor($coordinate / $cellSize) * $cellSize;
    }

    private function gridCellSizeDegrees(?int $radiusKm): float
    {
        return match (true) {
            $radiusKm === null => self::GRID_CELL_LARGE_DEGREES,
            $radiusKm <= self::SMALL_RADIUS_LIMIT_KM => self::GRID_CELL_SMALL_DEGREES,
            $radiusKm <= self::MEDIUM_RADIUS_LIMIT_KM => self::GRID_CELL_MEDIUM_DEGREES,
            default => self::GRID_CELL_LARGE_DEGREES,
        };
    }

    /**
     * Chave ausente equivale a versao 0, nao 1 — assim o primeiro `Cache::increment`
     * disparado por uma escrita relevante (que no Redis/array inicializa a chave
     * ausente em 0 e incrementa para 1) sempre produz uma versao nova de verdade,
     * mesmo que nenhuma escrita tenha acontecido antes dele.
     */
    private function currentVersion(): int
    {
        return (int) Cache::get(self::VERSION_CACHE_KEY, 0);
    }
}
