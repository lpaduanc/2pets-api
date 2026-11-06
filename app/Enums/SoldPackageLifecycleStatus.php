<?php

namespace App\Enums;

/**
 * Status DERIVADO do pacote vendido para a tela (PLANO 2.7), nunca gravado. Precedência:
 * cancelado > completo (sem saldo) > vencido > à vencer (≤ N dias, `package_expiring_soon_days`)
 * > novo (0 usos) > em uso. "À vencer" ganha de "Novo": pacote intocado perto de vencer é o
 * que a recepção precisa ver primeiro.
 */
enum SoldPackageLifecycleStatus: string
{
    case NEW = 'new';
    case IN_USE = 'in_use';
    case EXPIRING_SOON = 'expiring_soon';
    case EXPIRED = 'expired';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Novo',
            self::IN_USE => 'Em uso',
            self::EXPIRING_SOON => 'À vencer',
            self::EXPIRED => 'Vencido',
            self::COMPLETED => 'Completo',
            self::CANCELLED => 'Cancelado',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
