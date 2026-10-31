<?php

namespace App\Services\Crm;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Retorno médio do cliente (docs/gap-petflow/PLANO.md, 1.6): média dos intervalos, em dias,
 * entre compras consecutivas — dia no fuso America/Sao_Paulo. A média dos intervalos
 * consecutivos é (última − primeira) / (n − 1), então o relatório só precisa de contagem,
 * primeira e última data (`fromSpan`). Menos de 2 compras: `null` ("—" na tela). 1 casa.
 * Casos de referência em `tests/Fixtures/golden/performance.json`.
 */
final class PurchaseRhythmCalculator
{
    public const TIMEZONE = 'America/Sao_Paulo';

    /** @param  list<CarbonInterface|string>  $purchases  instantes das compras (qualquer ordem) */
    public function averageReturnDays(array $purchases): ?string
    {
        $days = array_map(fn (CarbonInterface|string $at): CarbonImmutable => CarbonImmutable::parse($at)->setTimezone(self::TIMEZONE)->startOfDay(), $purchases);
        usort($days, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        if (count($days) < 2) {
            return null;
        }

        $intervals = [];

        for ($index = 1; $index < count($days); $index++) {
            $intervals[] = (int) $days[$index - 1]->diffInDays($days[$index]);
        }

        return number_format(array_sum($intervals) / count($intervals), 1, '.', '');
    }

    /** Mesma conta a partir do agregado SQL: nº de compras e dias corridos entre a 1ª e a última. */
    public function fromSpan(int $purchaseCount, int $daysBetweenFirstAndLast): ?string
    {
        if ($purchaseCount < 2) {
            return null;
        }

        return number_format($daysBetweenFirstAndLast / ($purchaseCount - 1), 1, '.', '');
    }
}
