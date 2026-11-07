<?php

namespace App\Enums;

/** `pet_transports.type` — leva e traz (docs/gap-petflow/PLANO.md, 3.7). */
enum PetTransportType: string
{
    case PICKUP = 'pickup';
    case DROPOFF = 'dropoff';
    case ROUND_TRIP = 'round_trip';

    public function label(): string
    {
        return match ($this) {
            self::PICKUP => 'Só buscar',
            self::DROPOFF => 'Só levar',
            self::ROUND_TRIP => 'Leva e traz',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
