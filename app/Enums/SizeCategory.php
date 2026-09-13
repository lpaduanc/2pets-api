<?php

namespace App\Enums;

enum SizeCategory: string
{
    case MINI = 'mini';
    case SMALL = 'small';
    case MEDIUM = 'medium';
    case LARGE = 'large';
    case GIANT = 'giant';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`size_category.*`) — hoje só chamado
     * pelo schema de cadastro (`ProfessionalSchemaBuilder`), nenhum outro consumidor deste
     * enum usa `label()` (confirmado em 2026-09-13).
     */
    public function label(): string
    {
        return __('registration.size_category.'.$this->value);
    }
}
