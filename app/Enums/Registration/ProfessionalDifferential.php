<?php

namespace App\Enums\Registration;

/**
 * Diferenciais booleanos por tipo de negócio (`docs/segmentacao-cadastro-profissional.md`
 * §4.3) — os campos que `CompleteProfileProfessional.vue:1086-1116` já captura na tela e que
 * o backend descartava por não estarem em nenhuma `rules()`. Cada caso é a própria coluna em
 * `professionals`; `ACCEPTS_CREDIT_CARD`/`ACCEPTS_PET_INSURANCE` são o único bloco comum aos
 * 7 tipos, os demais são segmentados por `ProfessionalCapabilityRegistry`.
 */
enum ProfessionalDifferential: string
{
    case ACCEPTS_CREDIT_CARD = 'accepts_credit_card';
    case ACCEPTS_PET_INSURANCE = 'accepts_pet_insurance';
    case HOME_VISIT_AVAILABLE = 'home_visit_available';
    case ONLINE_CONSULTATION = 'online_consultation';
    case EMERGENCY_AVAILABLE = 'emergency_available';
    case EMERGENCY_24H = 'emergency_24h';
    case DELIVERY_AVAILABLE = 'delivery_available';
    case ONLINE_ORDERING = 'online_ordering';
    case CAGE_FREE_OPTION = 'cage_free_option';
    case WEBCAM_ACCESS = 'webcam_access';
    case SPECIAL_DIET_ACCOMMODATION = 'special_diet_accommodation';
    case MOBILE_SERVICE = 'mobile_service';
    case GROUP_SESSIONS_AVAILABLE = 'group_sessions_available';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`professional_differential.*`) —
     * também usado pela mensagem da dupla trava em `HasProfessionalCapabilityRules`, que
     * continua em pt-BR de propósito (locale só muda na rota do schema).
     */
    public function label(): string
    {
        return __('registration.professional_differential.'.$this->value);
    }
}
