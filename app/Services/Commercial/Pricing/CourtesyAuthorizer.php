<?php

namespace App\Services\Commercial\Pricing;

use App\Models\OrganizationMember;
use App\Models\Sale;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Quem pode marcar uma linha como brinde, cortesia ou retorno (`sales.grant_courtesy`, PLANO
 * 1.4) NESTA venda.
 *
 * O papel Spatie é global e soma o cadastro próprio da pessoa com todos os vínculos: uma
 * recepcionista que também se cadastrou como veterinária volante tem `vet_freelancer` — e com
 * ele a permissão, que vale para o balcão DELA, não para o da clínica onde atende. Por isso a
 * venda de uma organização é julgada pelo cargo da pessoa NAQUELA organização: o papel que o
 * cargo concede (`OrganizationRole::spatieRole`, o mesmo do `RolesAndPermissionsSeeder`) ou a
 * exceção pontual do dono (`organization_members.permissions`). Venda sem organização (vet
 * volante) usa a permissão da própria pessoa.
 */
final class CourtesyAuthorizer
{
    public const PERMISSION = 'sales.grant_courtesy';

    private const GUARD = 'web';

    public function allows(User $user, Sale $sale): bool
    {
        if ($sale->organization_id === null) {
            return $user->can(self::PERMISSION);
        }

        $membership = $user->activeOrganizationMemberships()
            ->where('organization_id', $sale->organization_id)
            ->with('organization')
            ->first();

        return $membership !== null && $this->membershipAllows($membership);
    }

    private function membershipAllows(OrganizationMember $membership): bool
    {
        if ($membership->hasPermission(self::PERMISSION)) {
            return true;
        }

        $role = Role::query()
            ->where('name', $membership->role->spatieRole($membership->organization->organization_type))
            ->where('guard_name', self::GUARD)
            ->first();

        return $role !== null && $role->permissions->contains('name', self::PERMISSION);
    }
}
