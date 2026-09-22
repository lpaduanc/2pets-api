<?php

namespace App\Enums;

/**
 * Classificação de risco/triagem capturada na admissão — contrato docs/gap-simplesvet/specs/
 * 12-internacao-mapa-execucao-spec.md §2. Escala tipo Manchester (conhecimento de mercado,
 * NÃO norma CFMV) — campo sempre opcional (regra de negócio 2), nunca bloqueia admissão.
 * Sem CHECK de banco de propósito (`hospitalizations.risk_level` é `varchar` simples): mesmo
 * raciocínio já aplicado a `inventory_movements.type`, vocabulário novo sem uso real ainda.
 */
enum HospitalizationRiskLevel: string
{
    case NON_URGENT = 'non_urgent';
    case LOW_URGENCY = 'low_urgency';
    case URGENT = 'urgent';
    case VERY_URGENT = 'very_urgent';
    case EMERGENCY = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::NON_URGENT => 'Não urgente',
            self::LOW_URGENCY => 'Pouco urgente',
            self::URGENT => 'Urgente',
            self::VERY_URGENT => 'Muito urgente',
            self::EMERGENCY => 'Emergência',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
