<?php

namespace App\Enums;

enum VetAccessLevel: string
{
    case READ = 'read';
    case WRITE = 'write';
    case FULL = 'full';

    public function label(): string
    {
        return match ($this) {
            self::READ => 'Leitura',
            self::WRITE => 'Leitura e Escrita',
            self::FULL => 'Acesso Completo',
        };
    }
}
