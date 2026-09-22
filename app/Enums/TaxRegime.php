<?php

namespace App\Enums;

/**
 * `organizations.tax_regime` / `users.tax_regime` (cliente PJ) — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
 */
enum TaxRegime: string
{
    case SIMPLES_NACIONAL = 'simples_nacional';
    case LUCRO_PRESUMIDO = 'lucro_presumido';
    case LUCRO_REAL = 'lucro_real';
    case MEI = 'mei';

    public function label(): string
    {
        return match ($this) {
            self::SIMPLES_NACIONAL => 'Simples Nacional',
            self::LUCRO_PRESUMIDO => 'Lucro Presumido',
            self::LUCRO_REAL => 'Lucro Real',
            self::MEI => 'MEI',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
