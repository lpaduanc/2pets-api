<?php

namespace App\Services\Organization;

use App\Enums\ProfessionalType;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Recalcula os papéis Spatie "profissionais" de uma pessoa a partir das duas fontes
 * legítimas — cadastro próprio (`users.user_type`) e vínculos ATIVOS em organizações
 * (`organization_members.is_active`) — e sincroniza só essa família de papéis.
 *
 * Antes desta classe, a atribuição de papel era sempre aditiva (`assignRole` em 3 pontos —
 * cadastro, aceite de convite, troca de cargo) e nenhum ponto revogava: um `clinic_vet`
 * desligado da única clínica mantinha o papel para sempre. `reconcile()` corrige isso sem
 * tocar em `admin`/`super_admin`, que são concedidos fora deste fluxo.
 */
final class UserRoleReconciler
{
    /**
     * Família de papéis derivados de cadastro próprio ou vínculo organizacional.
     * `veterinarian` é legado (`User::VET_ROLES`): nenhum ponto do sistema concede mais esse
     * nome, mas uma conta antiga que ainda o tenha é reconciliada como qualquer papel desta
     * família — nunca fica de fora por descuido.
     *
     * @var list<string>
     */
    private const MANAGED_ROLES = [
        'tutor',
        'vet_freelancer',
        'clinic_owner',
        'clinic_vet',
        'petshop_owner',
        'petshop_staff',
        'veterinarian',
    ];

    /**
     * Substitui os papéis desta família pelo conjunto correto — une o que falta e revoga o
     * que não é mais justificado — preservando intacto qualquer papel fora dela (`admin`,
     * `super_admin` ou futuro papel que não pertença a esta reconciliação).
     */
    public function reconcile(User $user): void
    {
        $finalRoles = $this->currentRolesOutsideManagedFamily($user)
            ->merge($this->expectedRoles($user))
            ->unique()
            ->values();

        $user->syncRoles($finalRoles->all());
    }

    /**
     * @return Collection<int, string>
     */
    private function expectedRoles(User $user): Collection
    {
        return $this->roleFromOwnRegistration($user)
            ->merge($this->rolesFromActiveMemberships($user))
            ->unique()
            ->values();
    }

    /**
     * Fonte A: papel que a pessoa tem por ser quem é, nunca por vínculo — não pode ser
     * revogado por perda de organização.
     *
     * @return Collection<int, string>
     */
    private function roleFromOwnRegistration(User $user): Collection
    {
        if ($user->user_type === 'tutor') {
            return collect(['tutor']);
        }

        $role = ProfessionalType::tryFrom((string) $user->user_type)?->defaultRoleName();

        return collect($role !== null ? [$role] : []);
    }

    /**
     * Fonte B: um papel por vínculo ativo, com o organograma da própria organização decidindo
     * qual papel Spatie o cargo concede.
     *
     * @return Collection<int, string>
     */
    private function rolesFromActiveMemberships(User $user): Collection
    {
        return $user->activeOrganizationMemberships()
            ->with('organization')
            ->get()
            ->map(fn (OrganizationMember $membership): string => $membership->role->spatieRole(
                $membership->organization->organization_type
            ))
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function currentRolesOutsideManagedFamily(User $user): Collection
    {
        return $user->roles()->pluck('name')->diff(self::MANAGED_ROLES)->values();
    }
}
