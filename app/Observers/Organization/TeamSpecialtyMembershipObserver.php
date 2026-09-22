<?php

namespace App\Observers\Organization;

use App\Models\OrganizationMember;
use App\Services\Organization\TeamSpecialtyAggregator;

/**
 * Fase 7 do fluxo de agendamento: entrada/saída de membro, ativação/desativação e troca de
 * cargo (recepcionista ↔ veterinário, por exemplo) — qualquer mudança em
 * `organization_members` que possa alterar QUEM conta como equipe bookável precisa
 * ressincronizar `professionals.team_specialties` do dono. Recalcula do zero sempre (nunca
 * incremental): mais simples de provar correto, e o custo é uma consulta pequena
 * (`OrganizationTeamService::bookableMembers()`), não um hot path de busca.
 */
final class TeamSpecialtyMembershipObserver
{
    public function __construct(private readonly TeamSpecialtyAggregator $aggregator) {}

    public function created(OrganizationMember $member): void
    {
        $this->sync($member);
    }

    public function updated(OrganizationMember $member): void
    {
        if (! $member->wasChanged(['is_active', 'role'])) {
            return;
        }

        $this->sync($member);
    }

    public function deleted(OrganizationMember $member): void
    {
        $this->sync($member);
    }

    private function sync(OrganizationMember $member): void
    {
        $organization = $member->organization;

        if ($organization !== null) {
            $this->aggregator->syncForOrganization($organization);
        }
    }
}
