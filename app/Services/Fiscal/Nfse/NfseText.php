<?php

namespace App\Services\Fiscal\Nfse;

use App\Support\Money\Decimal;

/**
 * Números para leitura humana na descrição da DPS e no DANFSe (pt-BR), sem passar por float:
 * "1500.5" → "1.500,50"; quantidade "2.0000" → "2", "1.5000" → "1,5".
 */
final class NfseText
{
    public static function money(?string $value): string
    {
        return self::grouped((string) Decimal::round($value ?? '0', 2));
    }

    public static function quantity(?string $value): string
    {
        $plain = (string) Decimal::of($value ?? '0')->stripTrailingZeros();

        return str_replace('.', ',', $plain);
    }

    private static function grouped(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        [$integer, $fraction] = explode('.', ltrim($decimal, '-'));
        $thousands = ltrim(strrev(implode('.', str_split(strrev($integer), 3))), '.');

        return ($negative ? '-' : '').$thousands.','.$fraction;
    }
}
