<?php

namespace App\Services\Appointment;

use App\Contracts\HasDepositSettings;
use App\Models\Organization;
use App\Models\Professional;
use App\Models\User;
use App\Services\Organization\OrganizationTeamService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Onde a configuração de sinal DESTE profissional mora" (Fase 6) — resolve o mesmo
 * invariante da Fase 1: vet volante nunca ganha `Organization` fictícia, então autônomo
 * configura no próprio `Professional`.
 *
 * LEITURA aceita três casos (dono, membro comum vendo a config da organização,
 * autônomo). ESCRITA só aceita dois (dono da organização, autônomo) — membro comum tenta
 * escrever e recebe 403, verificado com `OrganizationPolicy::manageDepositSettings` quando
 * há organização envolvida.
 */
final class EstablishmentDepositSettingsResolver
{
    public function __construct(private readonly OrganizationTeamService $teamService) {}

    public function forView(User $user): ?HasDepositSettings
    {
        $ownedOrganization = $this->teamService->resolveOwnedOrganization($user);

        if ($ownedOrganization !== null) {
            return $ownedOrganization;
        }

        $memberOrganizationId = $user->activeOrganizationMemberships()->value('organization_id');

        if ($memberOrganizationId !== null) {
            return Organization::find($memberOrganizationId);
        }

        return Professional::where('user_id', $user->id)->first();
    }

    /**
     * @throws AuthorizationException quando o usuário é membro comum (não-dono) de uma
     *                                organização — só o dono configura o sinal dela.
     */
    public function forWrite(User $user): Organization|Professional
    {
        $ownedOrganization = $this->teamService->resolveOwnedOrganization($user);

        if ($ownedOrganization !== null) {
            return $ownedOrganization;
        }

        if ($user->activeOrganizationMemberships()->exists()) {
            throw new AuthorizationException('Só o dono do estabelecimento configura o sinal.');
        }

        return Professional::where('user_id', $user->id)->firstOrFail();
    }
}
