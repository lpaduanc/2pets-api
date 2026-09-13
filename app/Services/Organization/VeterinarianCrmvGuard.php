<?php

namespace App\Services\Organization;

use App\Exceptions\Organization\CrmvRequiredForVeterinarianRoleException;
use App\Models\User;

/**
 * Regra regulatória não-negociável (Lei 5.517/1968 art. 1º; Res. CFMV 1.318/2020 e
 * 1.321/2020): ninguém vira `clinic_vet` sem CRMV preenchido. Único ponto de verdade dessa
 * checagem — usado tanto no aceite de convite (`OrganizationInvitationService`) quanto na
 * troca de cargo de um membro já existente (`OrganizationMemberService`), os dois únicos
 * caminhos que podem tornar alguém veterinário de uma organização.
 */
final class VeterinarianCrmvGuard
{
    public function ensureHasCrmv(User $user): void
    {
        $crmv = $user->professional?->crmv;

        if ($crmv === null || trim($crmv) === '') {
            throw new CrmvRequiredForVeterinarianRoleException;
        }
    }
}
