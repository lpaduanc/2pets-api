<?php

namespace App\Enums;

/** Dentro ou fora do estado do emitente — escolhe a faixa do CFOP (spec fase4-fiscal §3.4). */
enum FiscalCfopScope: string
{
    case INTRASTATE = 'intrastate';
    case INTERSTATE = 'interstate';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
