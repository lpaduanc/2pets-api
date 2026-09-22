<?php

namespace App\Enums;

/** `purchase_installments.status` — parcela a pagar da compra (ponte até o doc 03). */
enum PurchaseInstallmentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'A pagar',
            self::PAID => 'Paga',
            self::CANCELLED => 'Cancelada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
