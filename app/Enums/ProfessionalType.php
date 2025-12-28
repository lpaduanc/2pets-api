<?php

namespace App\Enums;

enum ProfessionalType: string
{
    case VETERINARIAN = 'veterinarian';
    case CLINIC = 'clinic';
    case PETSHOP = 'petshop';
    case GROOMER = 'groomer';
    case TRAINER = 'trainer';
    case PET_SITTER = 'pet_sitter';
    case DAYCARE = 'daycare';
    case LABORATORY = 'laboratory';
    case PHARMACY = 'pharmacy';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::VETERINARIAN => 'Veterinário',
            self::CLINIC => 'Clínica Veterinária',
            self::PETSHOP => 'Pet Shop',
            self::GROOMER => 'Banho e Tosa',
            self::TRAINER => 'Adestrador',
            self::PET_SITTER => 'Pet Sitter',
            self::DAYCARE => 'Creche/Hotel',
            self::LABORATORY => 'Laboratório',
            self::PHARMACY => 'Farmácia Veterinária',
            self::OTHER => 'Outro',
        };
    }
}

