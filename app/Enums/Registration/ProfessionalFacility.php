<?php

namespace App\Enums\Registration;

/**
 * Estrutura/comodidade do estabelecimento físico (`docs/segmentacao-cadastro-profissional.md`
 * §4.2). **Nunca para `vet`** — ele não tem prédio, é a queixa literal do dono do produto que
 * motivou esta segmentação. Cada caso já é a própria coluna em `professionals`.
 */
enum ProfessionalFacility: string
{
    case PARKING_AVAILABLE = 'parking_available';
    case WHEELCHAIR_ACCESSIBLE = 'wheelchair_accessible';

    /** Resolvido via `lang/{locale}/registration.php` (`professional_facility.*`). */
    public function label(): string
    {
        return __('registration.professional_facility.'.$this->value);
    }
}
