<?php

namespace App\Services\Booking;

use App\Models\OrganizationMember;
use DateTimeInterface;

/**
 * "Este profissional está de folga aprovada nesta data, dentro desta organização?" — item 21
 * do backlog gap-simplesvet. `OrganizationMember::isAvailableOn()` já existia, mas nada no
 * fluxo de agendamento público (`AvailabilityService`/`AvailableDaysCalculator`) chamava —
 * uma folga aprovada não impedia agendamento na prática, apesar do schema já suportar.
 *
 * Sem organização (vet volante, `$organizationId === null`) não há vínculo de equipe, logo
 * não há conceito de folga aprovada a checar — devolve sempre disponível.
 */
final class StaffTimeOffChecker
{
    public function isOnApprovedTimeOff(int $professionalUserId, ?int $organizationId, DateTimeInterface $date): bool
    {
        if ($organizationId === null) {
            return false;
        }

        $member = $this->activeMembership($professionalUserId, $organizationId);

        return $member !== null && ! $member->isAvailableOn($date);
    }

    private function activeMembership(int $professionalUserId, int $organizationId): ?OrganizationMember
    {
        return OrganizationMember::query()
            ->where('user_id', $professionalUserId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->first();
    }
}
