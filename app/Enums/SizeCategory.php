<?php

namespace App\Enums;

enum SizeCategory: string
{
    case MINI = 'mini';
    case SMALL = 'small';
    case MEDIUM = 'medium';
    case LARGE = 'large';
    case GIANT = 'giant';

    public function label(): string
    {
        return match ($this) {
            self::MINI => 'Mini',
            self::SMALL => 'Pequeno',
            self::MEDIUM => 'Médio',
            self::LARGE => 'Grande',
            self::GIANT => 'Gigante',
        };
    }
}
