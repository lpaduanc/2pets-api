<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PAID = 'paid';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::PROCESSING => 'Processando',
            self::PAID => 'Pago',
            self::FAILED => 'Falhou',
            self::REFUNDED => 'Reembolsado',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::PAID, self::REFUNDED, self::CANCELLED, self::FAILED]);
    }
}

