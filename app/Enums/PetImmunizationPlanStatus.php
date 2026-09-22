<?php

namespace App\Enums;

/**
 * Estado do plano de imunização do pet — não confundir com o estado de CADA dose
 * (`PetImmunizationDose::isOverdue()`/`isApplied()`), que é sempre derivado, nunca gravado.
 * Contrato docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md.
 */
enum PetImmunizationPlanStatus: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ABANDONED = 'abandoned';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
