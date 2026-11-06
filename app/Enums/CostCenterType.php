<?php

namespace App\Enums;

/** `cost_centers.type` (PLANO 2.4). */
enum CostCenterType: string
{
    case STORE = 'store';
    case GROOMING = 'grooming';
    case CLINIC = 'clinic';
    case HOTEL = 'hotel';
    case ADMINISTRATIVE = 'administrative';

    public function label(): string
    {
        return match ($this) {
            self::STORE => 'Loja',
            self::GROOMING => 'Banho e tosa',
            self::CLINIC => 'Clínica',
            self::HOTEL => 'Hotel',
            self::ADMINISTRATIVE => 'Administrativo',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
