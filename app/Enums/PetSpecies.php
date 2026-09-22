<?php

namespace App\Enums;

enum PetSpecies: string
{
    case DOG = 'dog';
    case CAT = 'cat';
    case BIRD = 'bird';
    case REPTILE = 'reptile';
    case RODENT = 'rodent';
    case FISH = 'fish';
    case OTHER = 'other';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`pet_species.*`) — hoje só chamado
     * pelo schema de cadastro (`ProfessionalSchemaBuilder`), nenhum outro consumidor deste
     * enum usa `label()` (confirmado em 2026-09-13).
     */
    public function label(): string
    {
        return __('registration.pet_species.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
