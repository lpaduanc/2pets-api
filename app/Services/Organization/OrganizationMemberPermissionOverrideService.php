<?php

namespace App\Services\Organization;

use App\Models\OrganizationMember;

/**
 * Grava a exceção pontual de permissão de um membro (`organization_members.permissions`) —
 * item 22 do backlog gap-simplesvet. A blindagem contra ato clínico já mora em
 * `OrganizationMember::hasPermission()` (leitura) e em
 * `UpdateOrganizationMemberPermissionsRequest` (escrita, 422 antecipado); este service só
 * persiste a lista já validada. Auditoria é automática — `permissions` está em
 * `OrganizationMember::getActivitylogOptions()`.
 */
final class OrganizationMemberPermissionOverrideService
{
    /**
     * @param  list<string>  $permissions
     */
    public function replace(OrganizationMember $member, array $permissions): OrganizationMember
    {
        $member->update(['permissions' => array_values(array_unique($permissions))]);

        return $member;
    }
}
