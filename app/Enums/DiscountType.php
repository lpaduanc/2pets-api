<?php

namespace App\Enums;

use App\Support\Money\Decimal;
use App\Support\Money\Money;

/**
 * `sales.discount_type` — `ven_cha_tipodesconto` do SimplesVet.
 *
 * Guardamos o TIPO junto com o valor (e não só o valor em reais já calculado) porque o
 * operador precisa reabrir a venda e ver "10%", não "R$ 23,47" — e porque adicionar um item
 * depois tem que recalcular o desconto percentual, coisa impossível se só o resultado foi
 * gravado.
 */
enum DiscountType: string
{
    case NONE = 'none';
    case AMOUNT = 'amount';
    case PERCENT = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Sem desconto',
            self::AMOUNT => 'Valor',
            self::PERCENT => 'Percentual',
        };
    }

    /** Desconto em reais sobre um subtotal, na moeda, já arredondado. */
    public function amountFor(float $subtotal, float $value): float
    {
        return $this->moneyFor(Money::of($subtotal), (string) Decimal::of($value))->toFloat();
    }

    /** Mesma regra de `amountFor`, exata: desconto em valor nunca passa do subtotal; percentual, de 100%. */
    public function moneyFor(Money $subtotal, string $value): Money
    {
        return match ($this) {
            self::NONE => Money::zero(),
            self::AMOUNT => Money::of($value)->min($subtotal),
            self::PERCENT => $subtotal->percentage(Decimal::of($value)->isGreaterThan(100) ? '100' : $value),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
