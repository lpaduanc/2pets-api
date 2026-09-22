<?php

namespace App\Services\Permission;

use App\Models\OrganizationMember;
use App\Models\User;

/**
 * Monta o resumo de autorização do usuário logado — item 22 do backlog
 * gap-simplesvet. Consumido por `GET me/permissions`: o app monta menu/UI a
 * partir disto, escondendo item sem permissão em vez de só desabilitá-lo.
 */
class PermissionSummaryService
{
    /**
     * @return array{
     *     permissions: list<string>,
     *     roles: list<string>,
     *     organization_roles: list<array{organization_id: int, role: string}>,
     * }
     */
    public function forUser(User $user): array
    {
        return [
            'permissions' => $this->effectivePermissions($user),
            'roles' => $user->getRoleNames()->values()->all(),
            'organization_roles' => $this->activeOrganizationRoles($user),
        ];
    }

    /**
     * Papel Spatie + exceção pontual por organização (`User::organizationPermissionOverrides()`)
     * — revisão de segurança, achado Médio 4: antes desta mudança, `GET me/permissions` nunca
     * refletia a exceção concedida em `PUT organizations/{org}/members/{member}/permissions`,
     * divergindo da autorização real aplicada por `EnsurePermission`.
     *
     * @return list<string>
     */
    private function effectivePermissions(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')
            ->merge($user->organizationPermissionOverrides())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{organization_id: int, role: string}>
     */
    private function activeOrganizationRoles(User $user): array
    {
        return OrganizationMember::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get(['organization_id', 'role'])
            ->map(fn (OrganizationMember $member): array => [
                'organization_id' => $member->organization_id,
                'role' => $member->role->value,
            ])
            ->all();
    }
}
