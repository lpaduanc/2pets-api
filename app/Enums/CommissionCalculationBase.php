<?php

namespace App\Enums;

/**
 * `commission_rules.calculation_base` — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, critérios de aceite.
 */
enum CommissionCalculationBase: string
{
    case GROSS = 'gross';
    case NET_OF_DISCOUNT = 'net_of_discount';
    case NET_OF_CARD_FEE = 'net_of_card_fee';
    case MARGIN = 'margin';

    public function label(): string
    {
        return match ($this) {
            self::GROSS => 'Valor cheio (sem desconto)',
            self::NET_OF_DISCOUNT => 'Líquido de desconto',
            self::NET_OF_CARD_FEE => 'Líquido da taxa da adquirente',
            self::MARGIN => 'Margem (venda − custo)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
