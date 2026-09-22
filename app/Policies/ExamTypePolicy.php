<?php

namespace App\Policies;

use App\Models\ExamType;
use App\Models\User;
use App\Support\Authorization\OrganizationCatalogGate;

class ExamTypePolicy
{
    public function view(User $user, ExamType $examType): bool
    {
        return $examType->organization_id === null
            || $user->isActiveMemberOfOrganization($examType->organization_id)
            || $user->ownsOrganization($examType->organization_id);
    }

    public function update(User $user, ExamType $examType): bool
    {
        return OrganizationCatalogGate::canManage($user, $examType->organization_id);
    }

    public function delete(User $user, ExamType $examType): bool
    {
        return OrganizationCatalogGate::canManage($user, $examType->organization_id);
    }
}
