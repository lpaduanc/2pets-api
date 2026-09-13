<?php

namespace App\Enums;

/**
 * Cargo de uma pessoa dentro de uma organização (`organization_members.role`) — Fase 2 do
 * split Pessoa/Organização. Os 6 valores já existiam como CHECK constraint desde a Fase 1
 * (`owner` + os 5 herdados de `staff.role`); este enum é a fonte de verdade do que cada um
 * concede, no lugar do JSON livre em `organization_members.permissions`
 * (ver `docs/rbac-clinica-autoria-e-staff.md` §6-7).
 */
enum OrganizationRole: string
{
    case OWNER = 'owner';
    case VETERINARIAN = 'veterinarian';
    case ASSISTANT = 'assistant';
    case RECEPTIONIST = 'receptionist';
    case GROOMER = 'groomer';
    case TECHNICIAN = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Proprietário',
            self::VETERINARIAN => 'Veterinário',
            self::ASSISTANT => 'Auxiliar',
            self::RECEPTIONIST => 'Atendente',
            self::GROOMER => 'Banho e Tosa',
            self::TECHNICIAN => 'Técnico',
        };
    }

    /**
     * Papel Spatie herdado por quem ocupa este cargo — é a fonte real de autorização de
     * plataforma (ver `User::VET_ROLES`, `PetPolicy`). Só é concedido de fato pela
     * reconciliação (`App\Services\Organization\UserRoleReconciler`), que soma este vínculo
     * a todo outro vínculo ativo e ao papel de cadastro próprio antes de sincronizar — a
     * mesma pessoa pode ter outro vínculo, com outro cargo, em outra organização, e não perde
     * o papel ganho ali só porque este aqui terminou.
     */
    public function spatieRole(OrganizationType $organizationType): string
    {
        return match ($this) {
            self::OWNER => in_array($organizationType, [OrganizationType::CLINIC, OrganizationType::LABORATORY], true)
                ? 'clinic_owner'
                : 'petshop_owner',
            self::VETERINARIAN => 'clinic_vet',
            self::ASSISTANT, self::RECEPTIONIST, self::GROOMER, self::TECHNICIAN => 'petshop_staff',
        };
    }

    /**
     * Único cargo que pratica ato clínico (prontuário, receita, exame, vacina, cirurgia,
     * internação) — Lei 5.517/1968 art. 1º; Res. CFMV 1.318/2020 e 1.321/2020. Usado por
     * `OrganizationMember::hasPermission()` para nunca deixar o JSON de `permissions`
     * conceder ato clínico a cargo não-clínico, e pelos dois únicos caminhos que podem tornar
     * alguém veterinário de uma organização (aceite de convite e troca de cargo).
     */
    public function isClinical(): bool
    {
        return $this === self::VETERINARIAN;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
