<?php

namespace App\Enums\Registration;

/**
 * Campos de enriquecimento de perfil que não são booleanos simples — cada um tem o próprio
 * tipo de dado, por isso não cabem em `ProfessionalDifferential`/`ProfessionalFacility`
 * (catálogos de toggle). `LANGUAGES_SPOKEN` é comum aos 7 tipos (comunicação com o cliente,
 * não estrutura física); os outros dois são exclusivos de um tipo — ver
 * `ProfessionalCapabilityRegistry`.
 */
enum ProfessionalAdditionalField: string
{
    case EXAM_ROOMS_COUNT = 'exam_rooms_count';
    case TRAINING_METHODOLOGY = 'training_methodology';
    case LANGUAGES_SPOKEN = 'languages_spoken';

    /** Resolvido via `lang/{locale}/registration.php` (`professional_additional_field.*`). */
    public function label(): string
    {
        return __('registration.professional_additional_field.'.$this->value);
    }

    /** @return 'integer'|'string'|'array' */
    public function dataType(): string
    {
        return match ($this) {
            self::EXAM_ROOMS_COUNT => 'integer',
            self::TRAINING_METHODOLOGY => 'string',
            self::LANGUAGES_SPOKEN => 'array',
        };
    }
}
