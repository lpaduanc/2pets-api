<?php

namespace App\Policies;

use App\Models\ClientOrigin;
use App\Models\User;
use App\Support\Authorization\OrganizationCatalogGate;

/** Mesma regra de `ImmunizationProductPolicy` — catálogo global vs. de organização. */
class ClientOriginPolicy
{
    public function view(User $user, ClientOrigin $clientOrigin): bool
    {
        return $clientOrigin->organization_id === null
            || $user->isActiveMemberOfOrganization($clientOrigin->organization_id)
            || $user->ownsOrganization($clientOrigin->organization_id);
    }

    public function update(User $user, ClientOrigin $clientOrigin): bool
    {
        return OrganizationCatalogGate::canManage($user, $clientOrigin->organization_id);
    }

    public function delete(User $user, ClientOrigin $clientOrigin): bool
    {
        return OrganizationCatalogGate::canManage($user, $clientOrigin->organization_id);
    }
}
