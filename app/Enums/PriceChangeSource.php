<?php

namespace App\Enums;

/** De onde veio uma mudança de preço do produto (`product_price_history.source`, PLANO 1.4). */
enum PriceChangeSource: string
{
    case MANUAL = 'manual';
    case PURCHASE = 'purchase';
    case IMPORT = 'import';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
