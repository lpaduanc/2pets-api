<?php

namespace App\Enums;

/**
 * Decide, para uma dose que depende de outra (`depends_on_dose_id`), se o atraso da dose
 * anterior empurra esta (`LAST_APPLICATION`, o caso comum) ou se a grade original é
 * preservada (`FIRST_APPLICATION`) — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md, regra de negócio 4/5.
 */
enum ImmunizationDoseAnchor: string
{
    case LAST_APPLICATION = 'last_application';
    case FIRST_APPLICATION = 'first_application';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
