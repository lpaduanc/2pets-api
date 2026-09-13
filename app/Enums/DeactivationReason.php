<?php

namespace App\Enums;

/**
 * Motivo declarado pela própria pessoa ao desativar a conta (`AccountDeactivationService`).
 * Conjunto fechado + `OTHER` com nota livre — é o insumo da campanha de reativação: sem um
 * motivo estruturado, "por que as pessoas saem" vira leitura manual de texto livre.
 */
enum DeactivationReason: string
{
    case NOT_USING = 'not_using';
    case FOUND_ALTERNATIVE = 'found_alternative';
    case TOO_EXPENSIVE = 'too_expensive';
    case BAD_EXPERIENCE = 'bad_experience';
    case PET_PASSED_AWAY = 'pet_passed_away';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NOT_USING => 'Não uso mais o aplicativo',
            self::FOUND_ALTERNATIVE => 'Encontrei outra solução',
            self::TOO_EXPENSIVE => 'Muito caro para mim',
            self::BAD_EXPERIENCE => 'Tive uma experiência ruim',
            self::PET_PASSED_AWAY => 'Meu pet faleceu',
            self::OTHER => 'Outro motivo',
        };
    }
}
