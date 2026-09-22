<?php

namespace App\Enums;

/**
 * `commission_settlements.status` — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de negócio 4:
 * fechamento fechado é imutável, mesmo princípio de nota fiscal emitida.
 */
enum CommissionSettlementStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case PAID = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Aberto',
            self::CLOSED => 'Fechado',
            self::PAID => 'Pago',
        };
    }

    public function isImmutable(): bool
    {
        return $this !== self::OPEN;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
