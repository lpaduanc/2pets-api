<?php

namespace App\Enums;

/**
 * Tipo de negócio de uma `Organization` — Fase 1 do split Pessoa/Organização.
 *
 * Espelha `ProfessionalType`, mas **sem `vet`**: o veterinário volante é sempre pessoa física
 * (um `User` com um `Professional`), nunca dono de uma organização com CNPJ. Uma clínica ou
 * petshop, ao contrário, tem CNPJ e existe independente de quem hoje é dono dela — por isso
 * ganha uma tabela própria (`organizations`) sem login.
 */
enum OrganizationType: string
{
    case CLINIC = 'clinic';
    case LABORATORY = 'laboratory';
    case PETSHOP = 'petshop';
    case PET_HOTEL = 'pet_hotel';
    case GROOMING = 'grooming';
    case TRAINING = 'training';

    public function label(): string
    {
        return match ($this) {
            self::CLINIC => 'Clínica Veterinária',
            self::LABORATORY => 'Laboratório',
            self::PETSHOP => 'Pet Shop',
            self::PET_HOTEL => 'Creche e Hotel',
            self::GROOMING => 'Banho e Tosa',
            self::TRAINING => 'Adestramento',
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
