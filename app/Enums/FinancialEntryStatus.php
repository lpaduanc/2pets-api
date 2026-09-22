<?php

namespace App\Enums;

/**
 * `financial_entries.status` — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * `PARTIALLY_PAID` existe porque a baixa aceita `paid_amount < amount` (pagamento parcial de
 * uma parcela). `CANCELLED` some do relatório sem apagar a linha — histórico contábil não se
 * apaga.
 */
enum FinancialEntryStatus: string
{
    case OPEN = 'open';
    case PAID = 'paid';
    case PARTIALLY_PAID = 'partially_paid';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Em aberto',
            self::PAID => 'Pago',
            self::PARTIALLY_PAID => 'Parcialmente pago',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::PAID;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
