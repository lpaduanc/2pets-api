<?php

namespace App\Enums;

/** `sold_package_sessions.status` (PLANO 2.7). Só `pending` ainda é receita diferida. */
enum SoldPackageSessionStatus: string
{
    case PENDING = 'pending';
    case CONSUMED = 'consumed';
    case EXPIRED = 'expired';
    case CREDITED = 'credited';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'A usar',
            self::CONSUMED => 'Usada',
            self::EXPIRED => 'Vencida (receita)',
            self::CREDITED => 'Vencida (crédito ao cliente)',
            self::CANCELLED => 'Cancelada',
        };
    }
}
