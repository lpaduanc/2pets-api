<?php

namespace App\Support\Money;

use Brick\Math\RoundingMode;

/**
 * Rateio ACUMULADO por quantidade — a mesma regra de `SaleItem::refundFor`, generalizada para
 * qualquer valor congelado (imposto, IPI, ST, acréscimo, valor de estoque). Spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.5/§3.6:
 *
 *   cum(F, q) = q ≥ total ? F : round(F × q / total)        (HALF_UP, 2 casas)
 *   parte desta devolução = cum(F, antes + q) − cum(F, antes)
 *
 * O devolvido até aqui vira centavo uma vez só; a última devolução leva o resto. Devolver tudo,
 * em quantas vezes for, devolve EXATAMENTE F — nunca um centavo a mais nem a menos.
 */
final class CumulativeShare
{
    public static function of(Money $amount, Quantity $before, Quantity $quantity, Quantity $total): Money
    {
        return self::cumulative($amount, $before->plus($quantity), $total)->minus(self::cumulative($amount, $before, $total));
    }

    private static function cumulative(Money $amount, Quantity $returned, Quantity $total): Money
    {
        if ($returned->isGreaterThanOrEqualTo($total)) {
            return $amount;
        }

        return Money::of($amount->toBigDecimal()->multipliedBy($returned->toBigDecimal())
            ->dividedBy($total->toBigDecimal(), Money::SCALE, RoundingMode::HalfUp));
    }
}
