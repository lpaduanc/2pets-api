<?php

namespace App\Enums;

/**
 * Rótulo de exibição/filtro do motor unificado de protocolo — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md, regra de negócio 2. Vacina,
 * vermífugo e antiparasitário compartilham a mesma máquina de agendamento; este enum só
 * decide como o item aparece na tela, nunca uma regra de cálculo diferente por grupo.
 */
enum ImmunizationGroup: string
{
    case VACCINE = 'vaccine';
    case DEWORMER = 'dewormer';
    case ANTIPARASITIC = 'antiparasitic';

    public function label(): string
    {
        return match ($this) {
            self::VACCINE => 'Vacina',
            self::DEWORMER => 'Vermífugo',
            self::ANTIPARASITIC => 'Antiparasitário',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
