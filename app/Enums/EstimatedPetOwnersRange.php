<?php

namespace App\Enums;

/**
 * Faixa estimada de colaboradores com pet, qualificação comercial do Clube de Vantagens
 * (`CompleteProfileCompany.vue` — select `estimated_pet_owners`, opcional).
 */
enum EstimatedPetOwnersRange: string
{
    case LESS_10 = 'less_10';
    case FROM_10_TO_25 = '10_25';
    case FROM_26_TO_50 = '26_50';
    case FROM_51_TO_75 = '51_75';
    case MORE_75 = 'more_75';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::LESS_10 => 'Menos de 10%',
            self::FROM_10_TO_25 => '10% a 25%',
            self::FROM_26_TO_50 => '26% a 50%',
            self::FROM_51_TO_75 => '51% a 75%',
            self::MORE_75 => 'Mais de 75%',
            self::UNKNOWN => 'Não sei informar',
        };
    }
}
