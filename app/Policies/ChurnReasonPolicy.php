<?php

namespace App\Policies;

use App\Models\ChurnReason;
use App\Models\User;
use App\Support\Authorization\OrganizationCatalogGate;

/** Mesma regra de `ImmunizationProductPolicy` — catálogo global vs. de organização. */
class ChurnReasonPolicy
{
    public function view(User $user, ChurnReason $churnReason): bool
    {
        return $churnReason->organization_id === null
            || $user->isActiveMemberOfOrganization($churnReason->organization_id)
            || $user->ownsOrganization($churnReason->organization_id);
    }

    public function update(User $user, ChurnReason $churnReason): bool
    {
        return OrganizationCatalogGate::canManage($user, $churnReason->organization_id);
    }

    public function delete(User $user, ChurnReason $churnReason): bool
    {
        return OrganizationCatalogGate::canManage($user, $churnReason->organization_id);
    }
}
