<?php

namespace App\Services\Commercial\Authorization;

use App\Models\OrganizationMember;
use App\Models\Sale;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Uma permissão julgada no contexto de UMA venda/organização (PLANO 1.4, 1.5 e 1.6):
 * `sales.grant_courtesy`, `reports.profitability`.
 *
 * O papel Spatie é global e soma o cadastro próprio da pessoa com todos os vínculos: uma
 * recepcionista que também se cadastrou como veterinária volante tem `vet_freelancer` — e com
 * ele as permissões, que valem para o balcão DELA, não para o da clínica onde atende. Por isso a
 * venda de uma organização é julgada pelo cargo da pessoa NAQUELA organização: o papel que o
 * cargo concede (`OrganizationRole::spatieRole`, o mesmo do `RolesAndPermissionsSeeder`) ou a
 * exceção pontual do dono (`organization_members.permissions`). Venda sem organização (vet
 * volante) usa a permissão da própria pessoa.
 */
final class SalePermissionGate
{
    /** Ver custo, margem e comissão (rentabilidade 1.5 e métricas de custo do BI 1.6). */
    public const PROFITABILITY = 'reports.profitability';

    private const GUARD = 'web';

    public function allows(User $user, Sale $sale, string $permission): bool
    {
        return $this->allowsInOrganization($user, $sale->organization_id, $permission);
    }

    /** Mesma regra, para uma organização (`null` = balcão próprio do vet volante). */
    public function allowsInOrganization(User $user, ?int $organizationId, string $permission): bool
    {
        if ($organizationId === null) {
            return $user->can($permission);
        }

        $membership = $user->activeOrganizationMemberships()
            ->where('organization_id', $organizationId)
            ->with('organization')
            ->first();

        return $membership !== null && $this->membershipAllows($membership, $permission);
    }

    /**
     * Para relatório que agrega o escopo comercial INTEIRO da pessoa (BI, PLANO 1.6): a
     * permissão tem de valer em TODA organização ativa dela — senão o cargo de dono numa
     * clínica abriria o custo de outra onde ela é atendente. Sem organização, a própria.
     */
    public function allowsAcrossScope(User $user, string $permission): bool
    {
        $memberships = $user->activeOrganizationMemberships()->with('organization')->get();

        if ($memberships->isEmpty()) {
            return $user->can($permission);
        }

        return $memberships->every(fn (OrganizationMember $membership): bool => $this->membershipAllows($membership, $permission));
    }

    private function membershipAllows(OrganizationMember $membership, string $permission): bool
    {
        if ($membership->hasPermission($permission)) {
            return true;
        }

        $role = Role::query()
            ->where('name', $membership->role->spatieRole($membership->organization->organization_type))
            ->where('guard_name', self::GUARD)
            ->first();

        return $role !== null && $role->permissions->contains('name', $permission);
    }
}
