<?php

namespace App\Enums;

/**
 * Forma farmacêutica do item de prescrição — slugs FIXADOS por
 * docs/atendimento-veterinario/03-contrato-receituario.md §4.
 */
enum PrescriptionPharmaceuticalForm: string
{
    case TABLET = 'tablet';
    case ORAL_SUSPENSION = 'oral_suspension';
    case INJECTABLE = 'injectable';
    case OINTMENT = 'ointment';
    case DROPS = 'drops';
    case SPRAY = 'spray';
    case SHAMPOO = 'shampoo';
    case CREAM = 'cream';
    case TRANSDERMAL_PATCH = 'transdermal_patch';
    case CHEWABLE = 'chewable';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TABLET => 'Comprimido',
            self::ORAL_SUSPENSION => 'Suspensão oral',
            self::INJECTABLE => 'Injetável',
            self::OINTMENT => 'Pomada',
            self::DROPS => 'Gotas',
            self::SPRAY => 'Spray',
            self::SHAMPOO => 'Shampoo',
            self::CREAM => 'Creme',
            self::TRANSDERMAL_PATCH => 'Adesivo transdérmico',
            self::CHEWABLE => 'Mastigável',
            self::OTHER => 'Outra',
        };
    }
}
