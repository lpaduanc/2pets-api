<?php

namespace App\Enums;

/**
 * `sold_packages.status` — contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 *
 * Só `CANCELLED` é gravado por uma ação explícita. `ACTIVE`, `CONSUMED` e `EXPIRED` são o
 * status EFETIVO, derivado em query por `SoldPackage::effectiveStatus()`/
 * `scopeWhereEffectiveStatus()` a partir do saldo e da validade — nunca reescrito por job, no
 * mesmo padrão de `Sale::effectiveQuoteStatus()` (não há scheduler rodando em dev, ver
 * `CLAUDE.md`).
 */
enum SoldPackageStatus: string
{
    case ACTIVE = 'active';
    case CONSUMED = 'consumed';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Ativo',
            self::CONSUMED => 'Consumido',
            self::EXPIRED => 'Vencido',
            self::CANCELLED => 'Cancelado',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
