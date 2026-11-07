<?php

namespace App\Enums;

/** `purchase_quotes.status` — cotação com fornecedores (docs/gap-petflow/PLANO.md, 3.5). */
enum PurchaseQuoteStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::SENT => 'Enviada',
            self::CLOSED => 'Encerrada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
