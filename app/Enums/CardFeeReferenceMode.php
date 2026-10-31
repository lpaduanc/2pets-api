<?php

namespace App\Enums;

/**
 * Qual taxa de cartão entra no preço mínimo (docs/gap-petflow/PLANO.md, 1.4; decisão 2):
 *  - `weighted_90d`: Σ taxa ÷ Σ bruto das parcelas de cartão dos últimos 90 dias — o custo real
 *    que a clínica pagou, no mix de bandeira/parcela dela (padrão);
 *  - `worst_case`: o maior MDR ativo cadastrado — o preço nunca dá prejuízo, mesmo no 12x.
 */
enum CardFeeReferenceMode: string
{
    case WEIGHTED_90D = 'weighted_90d';
    case WORST_CASE = 'worst_case';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
