<?php

namespace App\Enums;

/**
 * `receivable_anticipations.status` (PLANO 2.1). `simulated` existe no contrato do plano, mas a
 * simulação não persiste: no banco só há `confirmed` e `cancelled`.
 */
enum AnticipationStatus: string
{
    case SIMULATED = 'simulated';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
