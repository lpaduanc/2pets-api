<?php

namespace App\Enums;

/**
 * `organizations.expired_package_policy` — o que acontece com o valor das sessões não usadas
 * quando o pacote vence (PLANO 2.7, decisão pendente 1 da seção 6). Padrão `client_credit`
 * até a validação jurídica (CDC): o dinheiro pago vira crédito do cliente na clínica.
 */
enum ExpiredPackagePolicy: string
{
    case CLIENT_CREDIT = 'client_credit';
    case EXPIRATION_REVENUE = 'expiration_revenue';

    public function label(): string
    {
        return match ($this) {
            self::CLIENT_CREDIT => 'Crédito na conta do cliente',
            self::EXPIRATION_REVENUE => 'Receita de expiração',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
