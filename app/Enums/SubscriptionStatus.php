<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case TRIALING = 'trialing';
    case PAST_DUE = 'past_due';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Ativa',
            self::TRIALING => 'Período de Teste',
            self::PAST_DUE => 'Pagamento Pendente',
            self::CANCELLED => 'Cancelada',
            self::EXPIRED => 'Expirada',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::ACTIVE, self::TRIALING]);
    }
}

