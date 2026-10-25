<?php

namespace App\Enums\Wallet;

/** Como o tutor pagou o gasto (`tutor_expenses.payment_method`). Sempre opcional. */
enum ExpensePaymentMethod: string
{
    case PIX = 'pix';
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case CASH = 'cash';
    case BOLETO = 'boleto';

    public function label(): string
    {
        return match ($this) {
            self::PIX => 'Pix',
            self::CREDIT_CARD => 'Cartão de crédito',
            self::DEBIT_CARD => 'Cartão de débito',
            self::CASH => 'Dinheiro',
            self::BOLETO => 'Boleto',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
