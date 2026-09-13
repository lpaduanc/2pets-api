<?php

namespace App\Enums;

/**
 * Taxonomia canônica de tipo de profissional/negócio (7 tipos do MVP).
 *
 * Chave técnica na forma curta, espelhando `users.user_type` (~150 mil linhas já usam
 * essa forma) — ver `docs/taxonomia-professional-type.md` para a decisão completa e o
 * porquê de `pet_sitter`, `pharmacy` e `other` ficarem fora até V2/V3/nunca.
 */
enum ProfessionalType: string
{
    case VET = 'vet';
    case CLINIC = 'clinic';
    case LABORATORY = 'laboratory';
    case PETSHOP = 'petshop';
    case PET_HOTEL = 'pet_hotel';
    case GROOMING = 'grooming';
    case TRAINING = 'training';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`professional_type.*`) — pt-BR é o
     * default de `App::getLocale()`; só a rota do schema de cadastro troca o locale por
     * request (ver `App\Http\Middleware\SetLocaleFromAcceptLanguage`), então todo outro
     * consumidor deste método (busca pública, recursos de agenda, etc.) continua em pt-BR.
     */
    public function label(): string
    {
        return __('registration.professional_type.'.$this->value);
    }

    /**
     * Papel Spatie que um cadastro deste tipo recebe.
     *
     * Autorização no 2pets é decidida SEMPRE pelo papel Spatie — `users.role` é um balde
     * grosso (`tutor|professional|admin`) e `users.user_type` não é consultado por nenhum
     * guard. Sem papel atribuído, a conta existe mas é invisível para todo endpoint com
     * `hasAnyRole()`: o sintoma é 403 em funcionalidade que o usuário deveria ter.
     *
     * ⚠️ `laboratory`, `pet_hotel`, `grooming` e `training` caem em `petshop_owner` por ser o
     * papel de "dono de negócio não-clínico" mais próximo entre os 8 que existem
     * (`RolesAndPermissionsSeeder`). Papéis próprios para esses tipos são decisão de produto
     * pendente — confirmar com o pet-business-specialist antes de criar.
     */
    public function defaultRoleName(): string
    {
        return match ($this) {
            self::VET => 'vet_freelancer',
            self::CLINIC => 'clinic_owner',
            self::LABORATORY, self::PETSHOP, self::PET_HOTEL, self::GROOMING, self::TRAINING => 'petshop_owner',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
