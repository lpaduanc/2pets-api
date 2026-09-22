<?php

namespace App\Policies;

use App\Models\DocumentTemplate;
use App\Models\User;
use App\Support\Authorization\OrganizationCatalogGate;

class DocumentTemplatePolicy
{
    public function view(User $user, DocumentTemplate $documentTemplate): bool
    {
        return $documentTemplate->organization_id === null
            || $user->isActiveMemberOfOrganization($documentTemplate->organization_id)
            || $user->ownsOrganization($documentTemplate->organization_id);
    }

    public function update(User $user, DocumentTemplate $documentTemplate): bool
    {
        return OrganizationCatalogGate::canManage($user, $documentTemplate->organization_id);
    }

    public function delete(User $user, DocumentTemplate $documentTemplate): bool
    {
        return OrganizationCatalogGate::canManage($user, $documentTemplate->organization_id);
    }
}
