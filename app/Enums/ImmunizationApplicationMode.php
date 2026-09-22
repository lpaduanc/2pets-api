<?php

namespace App\Enums;

/**
 * `fixed_doses` exige `total_doses` preenchido (ex.: V8 filhote: 3 doses e acabou);
 * `indefinite` é o caso de reforço anual sem fim programado — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md.
 */
enum ImmunizationApplicationMode: string
{
    case INDEFINITE = 'indefinite';
    case FIXED_DOSES = 'fixed_doses';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
