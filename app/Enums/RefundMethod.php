<?php

namespace App\Enums;

/**
 * Como o cliente é ressarcido numa devolução (docs/gap-simplesvet/07). `store_credit` é o
 * crédito na conta corrente do cliente (doc 11), que ainda não tem livro próprio: por ora a
 * devolução só registra a intenção, e o doc 11 lê daqui quando entrar.
 */
enum RefundMethod: string
{
    case CASH = 'cash';
    case STORE_CREDIT = 'store_credit';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Devolução em dinheiro',
            self::STORE_CREDIT => 'Crédito para o cliente',
            self::NONE => 'Sem reembolso (troca)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
