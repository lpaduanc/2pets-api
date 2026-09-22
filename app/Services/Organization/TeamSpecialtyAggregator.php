<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\User;

/**
 * Fase 7 do fluxo de agendamento: espelha as especialidades da equipe BOOKÁVEL e ATIVA
 * (`OrganizationRole::bookableRoles()` — mesmo critério de `OrganizationTeamService`, nunca
 * recepcionista) na linha do `Professional` do DONO da organização
 * (`professionals.team_specialties`), para a busca continuar lendo UMA coluna só.
 *
 * Mantido por observer (`App\Observers\Organization\*`) — nunca chamado direto por um
 * controller. Entrada/saída de membro, mudança de especialidade e desativação de membro
 * todos passam por aqui, sempre recalculando do zero (nunca `attach`/incremental): membro
 * removido precisa SUMIR do agregado, não só deixar de ser adicionado de novo.
 */
final class TeamSpecialtyAggregator
{
    public function __construct(private readonly OrganizationTeamService $teamService) {}

    public function syncForOrganization(Organization $organization): void
    {
        $ownerProfessional = $this->ownerProfessional($organization);

        if ($ownerProfessional === null) {
            return;
        }

        $specialties = $this->activeBookableTeamSpecialties($organization);

        if (($ownerProfessional->team_specialties ?? []) === $specialties) {
            return;
        }

        $ownerProfessional->update(['team_specialties' => $specialties === [] ? null : $specialties]);
    }

    /**
     * Todas as organizações que este `User` integra (dono ou membro comum) — usado pelo
     * observer de `Professional` quando a PRÓPRIA especialidade de alguém muda: precisa
     * ressincronizar o agregado de toda organização de que essa pessoa participa, não só
     * a que ela possui.
     *
     * @return list<Organization>
     */
    public function organizationsInvolving(User $user): array
    {
        return Organization::query()
            ->whereIn('id', OrganizationMember::where('user_id', $user->id)->pluck('organization_id'))
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function activeBookableTeamSpecialties(Organization $organization): array
    {
        return $this->teamService->bookableMembers($organization)
            ->flatMap(fn (User $member): array => $member->professional?->specialties ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function ownerProfessional(Organization $organization): ?Professional
    {
        $ownerUserId = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('role', OrganizationMember::ROLE_OWNER)
            ->where('is_active', true)
            ->value('user_id');

        return $ownerUserId === null ? null : Professional::where('user_id', $ownerUserId)->first();
    }
}
