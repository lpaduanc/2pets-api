<?php

namespace App\Enums;

/**
 * Situação de uma parcela a receber da adquirente (docs/gap-petflow/PLANO.md, 1.1).
 * `ANTICIPATED` e `CHARGEBACK` já existem para as fases 2.1 (antecipação) e 2.3 (estorno).
 */
enum ReceivableStatus: string
{
    case PENDING = 'pending';
    case ANTICIPATED = 'anticipated';
    case SETTLED = 'settled';
    case CANCELLED = 'cancelled';
    case CHARGEBACK = 'chargeback';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'A receber',
            self::ANTICIPATED => 'Antecipada',
            self::SETTLED => 'Recebida',
            self::CANCELLED => 'Cancelada',
            self::CHARGEBACK => 'Estornada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
