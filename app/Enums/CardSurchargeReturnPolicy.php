<?php

namespace App\Enums;

/**
 * O que a devolução faz com o acréscimo de repasse da taxa do cartão (docs/gap-petflow/PLANO.md,
 * 1.1). Decisão do contador de cada clínica, por isso é configuração da organização:
 *  - `keep`: o acréscimo nunca volta — ele pagou a taxa da adquirente, que não é estornada;
 *  - `proportional`: volta a parte do acréscimo que cabe aos itens devolvidos.
 */
enum CardSurchargeReturnPolicy: string
{
    case KEEP = 'keep';
    case PROPORTIONAL = 'proportional';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
