<?php

namespace App\Enums;

/**
 * `ide/tpEmis` da NF-e/NFC-e: normal (1) ou contingência offline da NFC-e (9) — spec
 * fase4-fiscal §4. Só a NFC-e tem contingência offline; NF-e espera a SEFAZ voltar.
 */
enum FiscalEmissionMode: string
{
    case NORMAL = 'normal';
    case OFFLINE_CONTINGENCY = 'offline_contingency';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
