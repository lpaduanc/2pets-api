<?php

namespace App\Services\Finance\Managerial;

use App\Support\Money\Money;

/**
 * Chaves e soma dos insumos da DRE gerencial por centro de custo (PLANO 2.4). Cada fonte
 * (vendas, sessões de pacote, lançamentos) entrega `[centro][mês][chave] => valor`; o centro
 * `unallocated` ("Não rateado") é o que não tem centro — item sem centro (nem no grupo) ou
 * lançamento sem rateio. A DRE total é a soma de todos os centros, então a soma dos centros
 * bate com ela centavo a centavo por construção.
 */
final class ManagerialCostCenters
{
    public const UNALLOCATED = 'unallocated';

    /**
     * @param  array<string, array<string, array<string, string>>>  ...$sources
     * @return array<string, array<string, array<string, string>>>
     */
    public static function mergeCenters(array ...$sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            foreach ($source as $center => $months) {
                $merged[$center] = self::mergeMonths($merged[$center] ?? [], $months);
            }
        }

        return $merged;
    }

    /**
     * Soma de todos os centros num mapa só `[mês][chave]`.
     *
     * @param  array<string, array<string, array<string, string>>>  $byCenter
     * @return array<string, array<string, string>>
     */
    public static function total(array $byCenter): array
    {
        return array_reduce($byCenter, fn (array $carry, array $months): array => self::mergeMonths($carry, $months), []);
    }

    /**
     * @param  array<string, array<string, string>>  $a
     * @param  array<string, array<string, string>>  $b
     * @return array<string, array<string, string>>
     */
    public static function mergeMonths(array $a, array $b): array
    {
        foreach ($b as $month => $values) {
            foreach ($values as $key => $value) {
                $a[$month][$key] = Money::of($a[$month][$key] ?? '0')->plus($value)->toString();
            }
        }

        return $a;
    }
}
