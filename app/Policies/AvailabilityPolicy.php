<?php

namespace App\Policies;

use App\Models\Availability;
use App\Models\User;

/**
 * Agenda semanal (`availabilities`) é dado do profissional dono dela — mesma régua de
 * `InvoicePolicy::isAuthorOrOwner()`: o próprio profissional sempre escreve na própria
 * agenda; o dono (`OrganizationMember::ROLE_OWNER`) da organização a que ela pertence
 * também pode, para montar a grade de toda a equipe. Nenhum outro cargo (recepção,
 * assistente) grava agenda alheia.
 */
class AvailabilityPolicy
{
    public function manage(User $user, Availability $availability): bool
    {
        return $this->isOwnAgendaOrManagedByOrganizationOwner(
            $user,
            (int) $availability->professional_id,
            $availability->organization_id,
        );
    }

    /**
     * Ability de classe usada ao CRIAR uma janela nova para outro profissional
     * (`professional_id` informado no payload) — não existe ainda um `Availability` para
     * carregar `organization_id`, então a checagem parte do vínculo do ALVO com uma
     * organização que o usuário autenticado possui.
     */
    public function manageFor(User $user, User $targetProfessional): bool
    {
        if ($targetProfessional->id === $user->id) {
            return true;
        }

        return $this->sharedOwnedOrganizationId($user, $targetProfessional) !== null;
    }

    /**
     * Organização usada para gravar `organization_id` quando o dono da organização cria a
     * janela em nome de um colega. `null` quando é o próprio profissional (agenda pessoal,
     * sem organização) ou quando os dois não compartilham nenhuma organização — este último
     * caso nunca deveria chegar aqui, pois `manageFor()` já teria barrado antes.
     */
    public function sharedOwnedOrganizationId(User $user, User $targetProfessional): ?int
    {
        return $targetProfessional->activeOrganizationMemberships()
            ->pluck('organization_id')
            ->first(fn (int $organizationId): bool => $user->ownsOrganization($organizationId));
    }

    private function isOwnAgendaOrManagedByOrganizationOwner(User $user, int $professionalId, ?int $organizationId): bool
    {
        if ($professionalId === $user->id) {
            return true;
        }

        return $organizationId !== null && $user->ownsOrganization($organizationId);
    }
}
