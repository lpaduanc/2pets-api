<?php

namespace App\Enums;

/**
 * Taxonomia canônica de tipo de profissional/negócio (7 tipos do MVP).
 *
 * Chave técnica na forma curta, espelhando `users.user_type` (~150 mil linhas já usam
 * essa forma) — ver `docs/taxonomia-professional-type.md` para a decisão completa e o
 * porquê de `pet_sitter`, `pharmacy` e `other` ficarem fora até V2/V3/nunca.
 */
enum ProfessionalType: string
{
    case VET = 'vet';
    case CLINIC = 'clinic';
    case LABORATORY = 'laboratory';
    case PETSHOP = 'petshop';
    case PET_HOTEL = 'pet_hotel';
    case GROOMING = 'grooming';
    case TRAINING = 'training';

    public function label(): string
    {
        return match ($this) {
            self::VET => 'Veterinário Volante',
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
