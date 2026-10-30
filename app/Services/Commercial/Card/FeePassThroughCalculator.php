<?php

namespace App\Services\Commercial\Card;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Acréscimo de repasse da taxa ao cliente — docs/gap-petflow/PLANO.md, 1.1 ("Repasse opcional
 * ao cliente, por faixa").
 *
 * O cliente paga `cobrado` tal que o líquido da clínica, com a MESMA taxa de `feeFor`
 * (percentual sobre o cobrado, arredondado uma vez, + fixa), não fique abaixo do valor-base:
 *
 *   cobrado = ⌈(base + fixa) / (1 − p/100)⌉ no centavo   ·   acréscimo = cobrado − base
 *
 * Divisão exata em decimal e arredondamento para CIMA uma única vez: arredondar para baixo
 * deixaria a clínica com R$ 0,01 a menos. Garantia (ver teste): base ≤ líquido ≤ base + 0,01 —
 * o arredondamento HALF_UP da taxa sobre o cobrado nunca tira mais de meio centavo.
 */
final class FeePassThroughCalculator
{
    public function surchargeFor(Money $base, FeeTerms $terms): Money
    {
        if (! $terms->passToCustomer) {
            return Money::zero();
        }

        $remainingPercent = Decimal::of(100)->minus(Decimal::of($terms->feePercent));

        // Taxa de 100% (ou mais) não tem cobrado que deixe algum líquido: a conta divergiria.
        if (! $remainingPercent->isPositive()) {
            throw ValidationException::withMessages(['payment_method_id' => 'Taxa de 100% ou mais não pode ser repassada ao cliente.']);
        }

        $charged = $base->toBigDecimal()->plus(Decimal::of($terms->feeFixed))
            ->multipliedBy(100)
            ->dividedBy($remainingPercent, Money::SCALE, RoundingMode::Up);

        return Money::of($charged)->minus($base);
    }
}
