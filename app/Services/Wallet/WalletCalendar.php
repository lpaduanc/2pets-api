<?php

namespace App\Services\Wallet;

use Carbon\CarbonImmutable;

/**
 * "Hoje" da Carteira, no fuso do tutor.
 *
 * A aplicação roda em UTC (`config/app.php`). Às 22h em São Paulo já é o dia seguinte em
 * UTC: sem isto, um gasto lançado à noite cairia na data de amanhã e, no último dia do mês,
 * no mês seguinte. O app do tutor é só Brasil hoje; quando houver fuso por usuário, é aqui
 * que ele entra.
 */
final class WalletCalendar
{
    public const TIMEZONE = 'America/Sao_Paulo';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }
}
