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

    public function label(): string
    {
        return match ($this) {
            self::DOG => 'Cão',
            self::CAT => 'Gato',
            self::BIRD => 'Ave',
            self::REPTILE => 'Réptil',
            self::RODENT => 'Roedor',
            self::FISH => 'Peixe',
            self::OTHER => 'Outro',
        };
    }
}
