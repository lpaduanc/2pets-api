<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Porta de entrada de números decimais exatos — docs/gap-petflow/PLANO.md, seção 3.
 *
 * Os casts `decimal:N` do Eloquent devolvem string (exata). O `float` ainda chega de request
 * validada e de código legado: ele é convertido com 10 casas fixas, e não pelo `(string)` do
 * PHP, porque esse pode devolver notação científica (`1.0E-5`) e o `brick/math` vai deixar de
 * aceitar float na 0.15. Dez casas sobram para dinheiro (2), custo (4) e quantidade (3), e
 * absorvem o ruído binário (`2.675` vira `2.6750000000`, não `2.67499999…`).
 */
final class Decimal
{
    public static function of(BigNumber|int|float|string|null $value): BigDecimal
    {
        if (is_string($value)) {
            // O `(float) ' 10'` do código legado aceitava espaço; o `brick/math` não.
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        if (is_float($value)) {
            return BigDecimal::of(sprintf('%.10F', $value));
        }

        return BigDecimal::of($value);
    }

    /** Arredonda uma única vez, no fim do fato monetário — nunca em valor intermediário. */
    public static function round(BigNumber|int|float|string|null $value, int $scale, RoundingMode $mode = RoundingMode::HalfUp): BigDecimal
    {
        return self::of($value)->toScale($scale, $mode);
    }

    /** Ponte para assinaturas legadas que ainda devolvem `float`; o valor já chega arredondado. */
    public static function toFloat(BigNumber|int|float|string|null $value, int $scale): float
    {
        return self::round($value, $scale)->toFloat();
    }
}
