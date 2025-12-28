<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case PIX = 'pix';
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case BOLETO = 'boleto';

    public function label(): string
    {
        return match ($this) {
            self::PIX => 'PIX',
            self::CREDIT_CARD => 'Cartão de Crédito',
            self::DEBIT_CARD => 'Cartão de Débito',
            self::BOLETO => 'Boleto Bancário',
        };
    }

    public function allowsInstallments(): bool
    {
        return $this === self::CREDIT_CARD;
    }
}

