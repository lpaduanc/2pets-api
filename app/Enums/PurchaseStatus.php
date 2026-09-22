<?php

namespace App\Enums;

/**
 * `purchases.status` — docs/gap-simplesvet/06. Só `received` mexe em estoque e custo; o
 * rascunho é livre para editar (é a tela de conferência do XML), e o cancelamento estorna.
 */
enum PurchaseStatus: string
{
    case DRAFT = 'draft';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::RECEIVED => 'Recebida',
            self::CANCELLED => 'Cancelada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
