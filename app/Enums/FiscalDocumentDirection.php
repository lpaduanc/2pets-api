<?php

namespace App\Enums;

/** `tpNF` da NF-e: 0 = entrada, 1 = saída (spec fase4-fiscal §2). */
enum FiscalDocumentDirection: string
{
    case OUT = 'out';
    case IN = 'in';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
