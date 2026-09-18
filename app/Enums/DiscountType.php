<?php

namespace App\Enums;

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
        return match ($this) {
            self::NONE => 0.0,
            self::AMOUNT => round(min($value, $subtotal), 2),
            self::PERCENT => round($subtotal * (min($value, 100) / 100), 2),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
