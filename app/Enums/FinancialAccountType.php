<?php

namespace App\Enums;

/**
 * `financial_accounts.type` — contrato docs/gap-simplesvet/04-contas-bancarias-conciliacao-cartoes.md.
 *
 * `CARD_ACQUIRER` é o caso que justifica o enum existir: na conta de demonstração do SimplesVet,
 * "Cielo Master Card Crédito" é uma CONTA, não uma forma de pagamento. O dinheiro fica parado
 * na adquirente até o depósito e só então caminha para o banco — sem uma conta própria para a
 * operadora, esse dinheiro em trânsito ficaria invisível no caixa e o saldo do banco mentiria.
 */
enum FinancialAccountType: string
{
    case CHECKING = 'checking';
    case SAVINGS = 'savings';
    case CASH = 'cash';
    case CARD_ACQUIRER = 'card_acquirer';
    case WALLET = 'wallet';

    public function label(): string
    {
        return match ($this) {
            self::CHECKING => 'Conta corrente',
            self::SAVINGS => 'Poupança',
            self::CASH => 'Caixa (dinheiro)',
            self::CARD_ACQUIRER => 'Operadora de cartão',
            self::WALLET => 'Carteira digital',
        };
    }

    /** Só a conta da operadora participa da conciliação de cartões. */
    public function isAcquirer(): bool
    {
        return $this === self::CARD_ACQUIRER;
    }

    /** Conta que recebe a gaveta física do caixa (doc 01). */
    public function holdsPhysicalCash(): bool
    {
        return $this === self::CASH;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
