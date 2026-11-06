<?php

namespace App\Services\Commercial\Goals;

use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * A conta da meta de venda (docs/gap-petflow/PLANO.md, 2.5), pura — sem banco:
 *
 *   atingido%    = realizado / meta × 100                                  (2 casas HALF_UP)
 *   projeção     = round(realizado / dias_decorridos × dias_úteis_do_mês)  (HALF_UP, uma vez)
 *   projeção%    = projeção / meta × 100
 *   falta        = max(meta − realizado, 0)
 *   precisa/dia  = round(falta / dias_restantes)
 *
 * Bordas (golden `tests/Fixtures/golden/sales-goal.json`):
 *  - meta batida: falta 0 e precisa/dia 0 — nunca negativo;
 *  - 0 dias úteis decorridos (1º dia útil do mês, ou mês futuro): não há ritmo para extrapolar;
 *    a projeção é o próprio realizado (o que já entrou hoje), sem divisão por zero;
 *  - 0 dias úteis restantes (mês acabou, ou o resto do mês não tem dia útil): precisa/dia `null`
 *    quando ainda falta — não existe dia para vender; o `remaining_amount` mostra quanto faltou;
 *  - meta 0: percentuais `null`.
 */
final class SalesGoalCalculator
{
    /** @return array{achieved_percent: string|null, projection: string, projection_percent: string|null, remaining_amount: string, needed_per_day: string|null} */
    public function progress(string $target, string $actual, int $totalDays, int $elapsedDays, int $remainingDays): array
    {
        $goal = Money::of($target);
        $done = Money::of($actual);
        $projection = $elapsedDays > 0
            ? Money::of($done->toBigDecimal()->multipliedBy($totalDays)->dividedBy($elapsedDays, 10, RoundingMode::HalfUp))
            : $done;
        $missing = $goal->minus($done)->max(Money::zero());

        return [
            'achieved_percent' => self::percent($done, $goal),
            'projection' => $projection->toString(),
            'projection_percent' => self::percent($projection, $goal),
            'remaining_amount' => $missing->toString(),
            'needed_per_day' => $this->neededPerDay($missing, $remainingDays),
        ];
    }

    private function neededPerDay(Money $missing, int $remainingDays): ?string
    {
        if ($missing->isZero()) {
            return Money::zero()->toString();
        }

        if ($remainingDays < 1) {
            return null;
        }

        return Money::of($missing->toBigDecimal()->dividedBy($remainingDays, 10, RoundingMode::HalfUp))->toString();
    }

    private static function percent(Money $value, Money $goal): ?string
    {
        if (! $goal->isPositive()) {
            return null;
        }

        return (string) $value->toBigDecimal()->multipliedBy(100)->dividedBy($goal->toBigDecimal(), 2, RoundingMode::HalfUp);
    }
}
