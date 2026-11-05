<?php

namespace App\Enums;

/**
 * Como a adquirente cobra a antecipação (docs/gap-petflow/PLANO.md, 2.1): `monthly_rate` = taxa ao
 * mês pro rata dos dias até cada parcela (desconto simples sobre o líquido); `fixed_rate` = taxa
 * única sobre o total, rateada pelo líquido de cada parcela.
 */
enum AnticipationMode: string
{
    case MONTHLY_RATE = 'monthly_rate';
    case FIXED_RATE = 'fixed_rate';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
