<?php

namespace App\Services\Insights\Widgets;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * As contas pequenas dos widgets (PLANO 3.6), puras:
 *  - variação % = (atual − anterior) / anterior × 100, 2 casas HALF_UP; `null` quando o anterior
 *    não existe ou é 0 (a tela mostra "—");
 *  - janela de N dias terminando hoje, inclusiva: [hoje − (N − 1), hoje];
 *  - top N: ordena por valor decrescente (empate: pela chave, estável) e corta em N.
 */
final class WidgetMath
{
    public static function variationPercent(Money $current, ?Money $previous): ?string
    {
        if ($previous === null || $previous->isZero()) {
            return null;
        }

        return (string) $current->minus($previous)->toBigDecimal()->multipliedBy(100)->dividedBy($previous->toBigDecimal()->abs(), 2, RoundingMode::HalfUp);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function window(CarbonImmutable $today, int $days): array
    {
        return [$today->subDays(max(1, $days) - 1)->startOfDay(), $today->endOfDay()];
    }

    /**
     * @template T of array
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    public static function top(array $rows, string $valueKey, string $tieKey, int $limit): array
    {
        usort($rows, function (array $a, array $b) use ($valueKey, $tieKey): int {
            $byValue = Decimal::of($b[$valueKey])->compareTo(Decimal::of($a[$valueKey]));

            return $byValue !== 0 ? $byValue : strcmp((string) $a[$tieKey], (string) $b[$tieKey]);
        });

        return array_slice($rows, 0, $limit);
    }
}
