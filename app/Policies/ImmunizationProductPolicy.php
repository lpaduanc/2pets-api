<?php

namespace App\Policies;

use App\Models\ImmunizationProduct;
use App\Models\User;
use App\Support\Authorization\OrganizationCatalogGate;

class ImmunizationProductPolicy
{
    /** Catálogo global sempre visível; catálogo de organização, só a própria. */
    public function view(User $user, ImmunizationProduct $product): bool
    {
        return $product->organization_id === null
            || $user->isActiveMemberOfOrganization($product->organization_id)
            || $user->ownsOrganization($product->organization_id);
    }

    public function update(User $user, ImmunizationProduct $product): bool
    {
        return OrganizationCatalogGate::canManage($user, $product->organization_id);
    }

    public function delete(User $user, ImmunizationProduct $product): bool
    {
        return OrganizationCatalogGate::canManage($user, $product->organization_id);
    }
}
