<?php

namespace App\Services\ReferenceData;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache generico para dado de referencia (baixo volume, muda raramente): patologias,
 * catalogo de vacinas, marcas de racao, especialidades, alergias, restricoes
 * alimentares e racas (Fase 9 do plano de otimizacao — escopo explicitamente
 * autorizado pelo usuario, ao contrario de pet/prontuario/agenda).
 *
 * Mesmo padrao de invalidacao de `ProfessionalSearchCache`: contador de versao por
 * TABELA (nunca `KEYS`/`SCAN`, nunca `Cache::tags()`). Cada tabela tem seu proprio
 * contador para que salvar uma raca nao invalide o cache de patologias.
 */
final class ReferenceDataCacheService
{
    private const TTL_SECONDS = 86400; // 24h — dado de referencia muda raramente.

    /**
     * @param  array<string, scalar|null>  $variantFilters  os filtros de query string
     *                                                      que distinguem esta chamada de outra sobre a MESMA tabela (ex.: `species`).
     */
    public function remember(string $table, array $variantFilters, Closure $resultResolver): mixed
    {
        return Cache::remember($this->buildCacheKey($table, $variantFilters), self::TTL_SECONDS, $resultResolver);
    }

    public function versionKey(string $table): string
    {
        return "reference_data:{$table}:version";
    }

    /**
     * Chave ausente equivale a versao 0 — o mesmo raciocinio de
     * `ProfessionalSearchCache::currentVersion()`: o primeiro `Cache::increment` de
     * uma escrita relevante inicializa a chave ausente em 0 e incrementa para 1,
     * invalidando de verdade qualquer hit anterior guardado sob a versao implicita.
     */
    private function currentVersion(string $table): int
    {
        return (int) Cache::get($this->versionKey($table), 0);
    }

    private function buildCacheKey(string $table, array $variantFilters): string
    {
        $version = $this->currentVersion($table);
        $variantHash = md5(serialize($variantFilters));

        return "reference_data:{$table}:v{$version}:{$variantHash}";
    }
}
