<?php

namespace App\Enums;

/** `purchase_orders.status` — docs/gap-simplesvet/06, ciclo pedido → recebimento. */
enum PurchaseOrderStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case PARTIALLY_RECEIVED = 'partially_received';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::SENT => 'Enviado',
            self::PARTIALLY_RECEIVED => 'Recebido parcialmente',
            self::RECEIVED => 'Recebido',
            self::CANCELLED => 'Cancelado',
        };
    }

    /** Pedido que ainda pode receber mercadoria. */
    public function isReceivable(): bool
    {
        return in_array($this, [self::DRAFT, self::SENT, self::PARTIALLY_RECEIVED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
