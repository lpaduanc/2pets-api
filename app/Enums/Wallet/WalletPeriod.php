<?php

namespace App\Enums\Wallet;

use Carbon\CarbonImmutable;

/**
 * Janela do resumo da Carteira (`GET /wallet?period=`). Os valores são os que o app do tutor
 * já envia (`month`, `3m`, `6m`, `year`, `custom`).
 *
 * As janelas são de CALENDÁRIO, não "últimos N dias": "este mês" é do dia 1 até hoje, e
 * "3 meses" é o mês atual mais os dois anteriores. É o que o tutor entende ao comparar com a
 * fatura do cartão.
 */
enum WalletPeriod: string
{
    case MONTH = 'month';
    case THREE_MONTHS = '3m';
    case SIX_MONTHS = '6m';
    case YEAR = 'year';
    case CUSTOM = 'custom';

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(CarbonImmutable $today): array
    {
        return match ($this) {
            self::MONTH, self::CUSTOM => [$today->startOfMonth(), $today],
            self::THREE_MONTHS => [$today->subMonthsNoOverflow(2)->startOfMonth(), $today],
            self::SIX_MONTHS => [$today->subMonthsNoOverflow(5)->startOfMonth(), $today],
            self::YEAR => [$today->startOfYear(), $today],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
