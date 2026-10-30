<?php

namespace App\Enums;

/**
 * Bandeira do cartão no recebimento — a taxa da adquirente muda por bandeira
 * (docs/gap-petflow/PLANO.md, 1.1). `OTHER` cobre as bandeiras menores sem faixa própria.
 */
enum CardBrand: string
{
    case VISA = 'visa';
    case MASTERCARD = 'mastercard';
    case ELO = 'elo';
    case AMEX = 'amex';
    case HIPERCARD = 'hipercard';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::VISA => 'Visa',
            self::MASTERCARD => 'Mastercard',
            self::ELO => 'Elo',
            self::AMEX => 'American Express',
            self::HIPERCARD => 'Hipercard',
            self::OTHER => 'Outra',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
