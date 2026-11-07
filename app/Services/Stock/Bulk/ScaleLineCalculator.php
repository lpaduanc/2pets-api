<?php

namespace App\Services\Stock\Bulk;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Linha do PDV a partir da etiqueta da balança (PLANO 3.3), pura:
 *
 *  - modo `weight`: quantidade = gramas ÷ 1000 (3 casas); total = round(quantidade × preço/kg).
 *    A linha usa o preço do cadastro e o total sai da conta normal da linha — é o mesmo número.
 *  - modo `price`: total = o valor embutido, PRESERVADO EXATO; quantidade = round(total ÷ preço/kg,
 *    3). Como a linha guarda preço de 2 casas e total = quantidade × preço, o preço unitário da linha
 *    é o centavo mais perto de total ÷ quantidade que reproduz o total exato; se nenhum preço de 2
 *    casas reproduz (quantidade grande), sobe o preço até cobrir e o resto vira `discount` da linha
 *    (no máximo alguns centavos, "ajuste de etiqueta"). A quantidade é sempre a da etiqueta.
 * Casos em `tests/Fixtures/golden/scale.json`.
 */
final class ScaleLineCalculator
{
    /** @return array{quantity: string, unit_price: string, discount: string, total: string} */
    public function line(string $mode, int $value, string $pricePerKg): array
    {
        $price = Money::of($pricePerKg);

        if ($mode === 'weight') {
            $quantity = BigDecimal::of($value)->dividedBy(1000, 3, RoundingMode::Unnecessary);

            return ['quantity' => (string) $quantity, 'unit_price' => $price->toString(), 'discount' => '0.00', 'total' => Money::of($quantity->multipliedBy($price->toBigDecimal()))->toString()];
        }

        $total = Money::ofCents($value);
        $quantity = $price->isPositive() ? $total->toBigDecimal()->dividedBy($price->toBigDecimal(), 3, RoundingMode::HalfUp) : BigDecimal::of('1.000');
        $quantity = $quantity->isZero() ? BigDecimal::of('0.001') : $quantity;

        return ['quantity' => (string) $quantity] + $this->priceFor($quantity, $total) + ['total' => $total->toString()];
    }

    /** @return array{unit_price: string, discount: string} */
    private function priceFor(BigDecimal $quantity, Money $total): array
    {
        $ideal = Money::of($total->toBigDecimal()->dividedBy($quantity, 2, RoundingMode::HalfUp));

        foreach ([0, -1, 1, -2, 2, -3, 3] as $cents) {
            $candidate = $ideal->plus(Money::ofCents($cents));

            if ($candidate->isPositive() && Money::of($quantity->multipliedBy($candidate->toBigDecimal()))->isEqualTo($total)) {
                return ['unit_price' => $candidate->toString(), 'discount' => '0.00'];
            }
        }

        $up = Money::of($total->toBigDecimal()->dividedBy($quantity, 2, RoundingMode::Up));

        return ['unit_price' => $up->toString(), 'discount' => Money::of($quantity->multipliedBy($up->toBigDecimal()))->minus($total)->toString()];
    }
}
