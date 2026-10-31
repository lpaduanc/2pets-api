<?php

namespace App\Enums;

/**
 * Por que a linha da venda saiu de graça (`sale_items.courtesy_kind`, PLANO 1.4): brinde,
 * cortesia ou retorno incluso. Não muda a conta — muda o relatório (1.5) e a pendência fiscal de
 * bonificação (docs/PENDENCIAS.md).
 */
enum SaleItemCourtesyKind: string
{
    case GIFT = 'gift';
    case COURTESY = 'courtesy';
    case FOLLOW_UP = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::GIFT => 'Brinde',
            self::COURTESY => 'Cortesia',
            self::FOLLOW_UP => 'Retorno',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
