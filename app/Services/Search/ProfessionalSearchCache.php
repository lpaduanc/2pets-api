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

    /**
     * Total de sugestão envelhece bem: ele só ordena e dimensiona três alternativas, não
     * decide quem aparece na lista. Dez minutos cortam a recontagem sem risco de mostrar
     * número absurdo — e o contador de versão continua invalidando na hora em que alguém
     * escreve.
     */
    private const COUNT_TTL_SECONDS = 600;

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

    /**
     * O total já cacheado, ou `null` quando ainda não foi calculado.
     *
     * Existe para o chamador poder colher de graça o que já está no Redis ANTES de decidir
     * quais contagens cabem no orçamento de latência dele — sem isso, a ordem de cálculo
     * seria a ordem do vocabulário e uma contagem cara na frente esconderia três baratas
     * atrás dela.
     */
    public function cachedCount(SearchFiltersDTO $filters): ?int
    {
        $cached = Cache::get($this->buildCountCacheKey($filters));

        return $cached === null ? null : (int) $cached;
    }

    /**
     * Grava o total de uma busca em namespace próprio.
     *
     * Separado de `remember()` de propósito: aquela entrada guarda `{ids, total}` e vale
     * 60 s porque alimenta a LISTA que o usuário vê; um total que só ordena três sugestões
     * tolera muito mais desatualização, e recontar a cada minuto seria desperdício. As duas
     * compartilham o contador de versão, então uma escrita relevante invalida as duas.
     */
    public function putCount(SearchFiltersDTO $filters, int $total): void
    {
        Cache::put($this->buildCountCacheKey($filters), $total, self::COUNT_TTL_SECONDS);
    }

    private function buildCountCacheKey(SearchFiltersDTO $filters): string
    {
        return 'professional_search_count:'.$this->buildCacheKey($filters);
    }

    /**
     * INVARIANTE: todo campo de `SearchFiltersDTO` que muda QUEM entra no resultado precisa
     * entrar nesta chave. Filtro novo esquecido aqui faz duas buscas diferentes colidirem e
     * uma receber o resultado da outra — erro silencioso, sem exceção e sem log.
     *
     * Os únicos campos deliberadamente de fora são `page` e `per_page`: o cache guarda a
     * lista de ids inteira (até `MAX_CACHED_IDS`) e quem fatia a página é o PHP, então
     * paginar não muda o conteúdo cacheado. `ProfessionalSearchCacheTest` cobre isso.
     *
     * As quatro dimensões multivaloradas entram como LISTA. A forma canônica (deduplicada e
     * ordenada) é responsabilidade de `SearchFiltersDTO::normalizeFilterList()`, não daqui:
     * `?type[]=vet&type[]=clinic` e `?type[]=clinic&type[]=vet` são a MESMA busca (OR é
     * comutativo) e precisam cair na mesma entrada, senão o acerto do cache cai pela metade
     * a cada valor extra marcado no filtro.
     */
    private function buildCacheKey(SearchFiltersDTO $filters): string
    {
        $keyData = [
            'lat' => $this->snapToGrid($filters->latitude, $filters->radiusKm),
            'lng' => $this->snapToGrid($filters->longitude, $filters->radiusKm),
            'radius' => $filters->radiusKm,
            'type' => $filters->professionalTypes,
            'category' => $filters->serviceCategories,
            'min_price' => $filters->minPrice,
            'max_price' => $filters->maxPrice,
            'min_rating' => $filters->minRating,
            'query' => $filters->searchQuery,
            'sort' => $filters->sortBy,
            'specialty' => $filters->specialties,
            'species' => $filters->species,
            // `search()` hoje nem chega ao cache quando `available_now` está ligado (o
            // filtro depende do minuto atual). Entra na chave mesmo assim: se alguém um dia
            // remover esse desvio, a alternativa é duas buscas diferentes compartilharem
            // resultado em silêncio — bug de precisão que não dá erro em lugar nenhum.
            'available_now' => $filters->availableNow,
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
