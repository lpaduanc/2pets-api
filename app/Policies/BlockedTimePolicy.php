<?php

namespace App\Policies;

use App\Models\BlockedTime;
use App\Models\User;

/**
 * Bloqueio de agenda (férias, almoço, compromisso) — mesma régua de `AvailabilityPolicy`:
 * o próprio profissional sempre escreve o próprio bloqueio; o dono da organização a que o
 * bloqueio pertence também pode, para bloquear a agenda de um colega (ex.: férias
 * lançadas pela administração).
 */
class BlockedTimePolicy
{
    public function manage(User $user, BlockedTime $blockedTime): bool
    {
        return $this->isOwnAgendaOrManagedByOrganizationOwner(
            $user,
            (int) $blockedTime->professional_id,
            $blockedTime->organization_id,
        );
    }

    /**
     * Ability de classe usada ao CRIAR um bloqueio novo para outro profissional
     * (`professional_id` informado no payload) — mesmo raciocínio de
     * `AvailabilityPolicy::manageFor()`.
     */
    public function manageFor(User $user, User $targetProfessional): bool
    {
        if ($targetProfessional->id === $user->id) {
            return true;
        }

        return $targetProfessional->activeOrganizationMemberships()
            ->pluck('organization_id')
            ->contains(fn (int $organizationId): bool => $user->ownsOrganization($organizationId));
    }

    private function isOwnAgendaOrManagedByOrganizationOwner(User $user, int $professionalId, ?int $organizationId): bool
    {
        if ($professionalId === $user->id) {
            return true;
        }

        return $organizationId !== null && $user->ownsOrganization($organizationId);
    }
}
