<?php

namespace App\Services\Insights;

use App\Support\Money\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Curva ABC por margem de contribuição (docs/gap-petflow/PLANO.md, 1.6). Pura.
 *
 *  - ordena por MC desc; empate pelo `bucket` asc (`null` por último) — mesma entrada, mesma
 *    classificação, sempre;
 *  - participação sobre a Σ das MCs POSITIVAS; classe pelo acumulado ANTES do item: < 80% → A,
 *    < 95% → B, senão C (o item que cruza 80% ainda é A — senão um campeão sozinho com 90% da MC
 *    seria "B");
 *  - MC ≤ 0 → C sem acumulado (não "consome" participação); `loss: true` só com MC < 0 — MC zero
 *    não é prejuízo (ex.: item cuja venda ainda está aberta, sem rentabilidade calculada).
 *
 * Casos de referência em `tests/Fixtures/golden/performance.json`.
 */
final class InsightAbcClassifier
{
    private const A_LIMIT = 80;

    private const B_LIMIT = 95;

    /**
     * @param  list<array{bucket: string|int|null, contribution_margin: string}>  $points
     * @return array<string, array{class: string, cumulative_percent: string|null, loss: bool}> por `(string) bucket`
     */
    public function classify(array $points): array
    {
        $sorted = $this->sorted($points);
        $positiveTotal = array_reduce($sorted, fn (BigDecimal $sum, array $point): BigDecimal => self::margin($point)->isPositive() ? $sum->plus(self::margin($point)) : $sum, BigDecimal::zero());
        $before = BigDecimal::zero();
        $classes = [];

        foreach ($sorted as $point) {
            $classes[(string) $point['bucket']] = $this->classOf(self::margin($point), $before, $positiveTotal);
            $before = self::margin($point)->isPositive() ? $before->plus(self::margin($point)) : $before;
        }

        return $classes;
    }

    /** @return array{class: string, cumulative_percent: string|null, loss: bool} */
    private function classOf(BigDecimal $margin, BigDecimal $before, BigDecimal $total): array
    {
        if (! $margin->isPositive()) {
            return ['class' => 'C', 'cumulative_percent' => null, 'loss' => $margin->isNegative()];
        }

        $shareBefore = $before->multipliedBy(100)->dividedBy($total, 10, RoundingMode::HalfUp);
        $class = match (true) {
            $shareBefore->isLessThan(self::A_LIMIT) => 'A',
            $shareBefore->isLessThan(self::B_LIMIT) => 'B',
            default => 'C',
        };

        return [
            'class' => $class,
            'cumulative_percent' => (string) $before->plus($margin)->multipliedBy(100)->dividedBy($total, 2, RoundingMode::HalfUp),
            'loss' => false,
        ];
    }

    /**
     * @param  list<array{bucket: string|int|null, contribution_margin: string}>  $points
     * @return list<array{bucket: string|int|null, contribution_margin: string}>
     */
    private function sorted(array $points): array
    {
        usort($points, fn (array $a, array $b): int => self::margin($b)->compareTo(self::margin($a))
            ?: (($a['bucket'] === null) <=> ($b['bucket'] === null))
            ?: strnatcmp((string) $a['bucket'], (string) $b['bucket']));

        return $points;
    }

    /** @param  array{contribution_margin: string}  $point */
    private static function margin(array $point): BigDecimal
    {
        return Decimal::of($point['contribution_margin']);
    }
}
