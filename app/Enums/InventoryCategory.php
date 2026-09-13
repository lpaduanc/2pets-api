<?php

namespace App\Enums;

enum InventoryCategory: string
{
    case MEDICATION = 'medication';
    case VACCINE = 'vaccine';
    case SUPPLY = 'supply';
    case EQUIPMENT = 'equipment';

    public function label(): string
    {
        return match ($this) {
            self::MEDICATION => 'Medicamento',
            self::VACCINE => 'Vacina',
            self::SUPPLY => 'Insumo',
            self::EQUIPMENT => 'Equipamento',
        };
    }
}
