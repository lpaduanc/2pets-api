<?php

namespace App\Enums;

/**
 * `purchase_quote_suppliers.status` (PLANO 3.5). `expired` não é gravado: é o status EFETIVO de
 * quem ainda não respondeu quando o prazo (`valid_until`) passa — mesmo desenho do orçamento ao
 * cliente (`Sale::effectiveQuoteStatus`), sem job para virar o status à meia-noite.
 */
enum PurchaseQuoteSupplierStatus: string
{
    case REQUESTED = 'requested';
    case VIEWED = 'viewed';
    case RESPONDED = 'responded';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Solicitada',
            self::VIEWED => 'Visualizada',
            self::RESPONDED => 'Respondida',
            self::REJECTED => 'Recusada',
            self::EXPIRED => 'Vencida',
        };
    }

    /** Ainda aguardando o fornecedor (pode vencer). */
    public function isAwaiting(): bool
    {
        return in_array($this, [self::REQUESTED, self::VIEWED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
