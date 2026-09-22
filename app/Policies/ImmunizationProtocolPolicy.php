<?php

namespace App\Policies;

use App\Models\ImmunizationProtocol;
use App\Models\User;
use App\Support\Authorization\OrganizationCatalogGate;

class ImmunizationProtocolPolicy
{
    public function view(User $user, ImmunizationProtocol $protocol): bool
    {
        return $protocol->organization_id === null
            || $user->isActiveMemberOfOrganization($protocol->organization_id)
            || $user->ownsOrganization($protocol->organization_id);
    }

    public function update(User $user, ImmunizationProtocol $protocol): bool
    {
        return OrganizationCatalogGate::canManage($user, $protocol->organization_id);
    }

    public function delete(User $user, ImmunizationProtocol $protocol): bool
    {
        return OrganizationCatalogGate::canManage($user, $protocol->organization_id);
    }
}
