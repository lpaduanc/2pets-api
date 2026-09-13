<?php

namespace App\Services\Organization;

use App\Enums\OrganizationRole;
use App\Exceptions\Organization\LastActiveOwnerCannotBeRemovedException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Collection;

final class OrganizationMemberService
{
    public function __construct(
        private readonly VeterinarianCrmvGuard $crmvGuard,
        private readonly UserRoleReconciler $roleReconciler,
    ) {}

    /**
     * @return Collection<int, OrganizationMember>
     */
    public function listMembers(Organization $organization): Collection
    {
        return $organization->members()
            ->with('user.professional')
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->get();
    }

    public function updateMember(OrganizationMember $member, ?OrganizationRole $role, ?bool $isActive): OrganizationMember
    {
        if ($role !== null) {
            $this->changeRole($member, $role);
        }

        if ($isActive !== null) {
            $this->changeActiveStatus($member, $isActive);
        }

        return $member->refresh();
    }

    /**
     * "Desliga": nunca delete físico — is_active=false + termination_date, mesma regra de
     * soft delete do resto do projeto. Bloqueado se `$member` for o último owner ativo.
     */
    public function deactivate(OrganizationMember $member): OrganizationMember
    {
        $this->ensureOrganizationKeepsAnActiveOwner($member);

        $member->update([
            'is_active' => false,
            'termination_date' => now()->toDateString(),
        ]);
        $this->roleReconciler->reconcile($member->user);

        return $member;
    }

    private function changeRole(OrganizationMember $member, OrganizationRole $role): void
    {
        if ($role !== OrganizationRole::OWNER) {
            $this->ensureOrganizationKeepsAnActiveOwner($member);
        }

        if ($role->isClinical()) {
            $this->crmvGuard->ensureHasCrmv($member->user);
        }

        $member->update(['role' => $role]);
        $this->roleReconciler->reconcile($member->user);
    }

    private function changeActiveStatus(OrganizationMember $member, bool $isActive): void
    {
        if (! $isActive) {
            $this->ensureOrganizationKeepsAnActiveOwner($member);
        }

        $member->update([
            'is_active' => $isActive,
            'termination_date' => $isActive ? null : now()->toDateString(),
        ]);
        $this->roleReconciler->reconcile($member->user);
    }

    /**
     * "Último owner ativo" só importa quando `$member` já é, ele mesmo, um owner ativo —
     * mudar o cargo de qualquer outra pessoa, ou desligar um não-owner, nunca esbarra aqui.
     */
    private function ensureOrganizationKeepsAnActiveOwner(OrganizationMember $member): void
    {
        if ($member->role !== OrganizationRole::OWNER || ! $member->is_active) {
            return;
        }

        $hasAnotherActiveOwner = OrganizationMember::query()
            ->where('organization_id', $member->organization_id)
            ->where('role', OrganizationMember::ROLE_OWNER)
            ->where('is_active', true)
            ->where('id', '!=', $member->id)
            ->exists();

        if (! $hasAnotherActiveOwner) {
            throw new LastActiveOwnerCannotBeRemovedException;
        }
    }
}
