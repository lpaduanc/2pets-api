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
}
