<?php

namespace App\Enums;

/**
 * `ide/tpAmb`: homologação (2) ou produção (1). Padrão homologação — nenhuma clínica emite
 * em produção sem ter escolhido isso de propósito. A numeração é separada por ambiente.
 */
enum FiscalEnvironment: string
{
    case HOMOLOGATION = 'homologation';
    case PRODUCTION = 'production';

    public function label(): string
    {
        return match ($this) {
            self::HOMOLOGATION => 'Homologação (teste)',
            self::PRODUCTION => 'Produção',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
