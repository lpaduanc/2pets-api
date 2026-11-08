<?php

namespace App\Services\Fiscal\Sefaz;

/**
 * Classificação do cStat devolvido pela SEFAZ — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §7.2 (tabela de cStat).
 */
enum SefazOutcome: string
{
    case AUTHORIZED = 'authorized';
    case DENIED = 'denied';
    case PROCESSING = 'processing';
    case DUPLICATE = 'duplicate';
    case UNAVAILABLE = 'unavailable';
    case REJECTED = 'rejected';
}
