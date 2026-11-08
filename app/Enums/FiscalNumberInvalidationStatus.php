<?php

namespace App\Enums;

/** Situação de um pedido de inutilização de faixa de numeração (spec fase4-fiscal §3.7). */
enum FiscalNumberInvalidationStatus: string
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::AUTHORIZED => 'Homologada',
            self::REJECTED => 'Rejeitada',
        };
    }

    /** Pendente ou homologada ocupa a faixa: outra inutilização não pode se sobrepor a ela. */
    public function blocksRange(): bool
    {
        return $this !== self::REJECTED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
