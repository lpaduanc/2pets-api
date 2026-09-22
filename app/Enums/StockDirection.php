<?php

namespace App\Enums;

enum StockDirection: string
{
    case IN = 'in';
    case OUT = 'out';

    public function sign(): int
    {
        return $this === self::IN ? 1 : -1;
    }
}
