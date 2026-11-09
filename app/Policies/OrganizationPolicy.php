<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;

/**
 * Só `owner` ativo da organização gerencia membros e convites dela — "futuro admin" citado no
 * escopo da Fase 2 ainda não existe como cargo (`OrganizationRole` não tem `admin`), então por
 * ora é só `owner`. Ninguém gerencia organização da qual não é membro — mesma defesa contra
 * IDOR que `PetPolicy`/`AuthorizesPetAccess` aplicam a pet.
 */
class OrganizationPolicy
{
    public function manageMembers(User $user, Organization $organization): bool
    {
        return OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('role', OrganizationMember::ROLE_OWNER)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * `PUT professional/deposit-settings` — Fase 6 do fluxo de agendamento: configurar o
     * sinal (percentual + liga/desliga) da organização é decisão de negócio do dono, mesma
     * régua de `manageMembers`.
     */
    public function manageDepositSettings(User $user, Organization $organization): bool
    {
        return $this->manageMembers($user, $organization);
    }

    /**
     * `GET/PUT professional/organization` — razão social, CNPJ e endereço do emitente da NF-e.
     * Só o dono: é o dado legal da empresa, e a exceção pontual de permissão
     * (`organization_members.permissions`) que deixa passar o middleware não basta aqui.
     */
    public function manageCompanyProfile(User $user, Organization $organization): bool
    {
        return $this->manageMembers($user, $organization);
    }
}
