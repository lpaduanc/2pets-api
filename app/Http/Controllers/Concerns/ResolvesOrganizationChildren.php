<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;

/**
 * Resolve um membro/convite garantindo que pertence à organização da URL — nunca confiar que
 * `{member}`/`{invitation}` da rota já pertence à `{organization}` também da rota. Mesma
 * defesa contra IDOR que `AuthorizesPetAccess` aplica a pet: quem é dono da organização A não
 * pode enxergar nem mexer num membro/convite da organização B só por adivinhar o id.
 */
trait ResolvesOrganizationChildren
{
    protected function resolveMember(Organization $organization, int $memberId): OrganizationMember
    {
        return $organization->members()
            ->with(['user.professional', 'organization', 'serviceAreas'])
            ->findOrFail($memberId);
    }

    protected function resolveInvitation(Organization $organization, int $invitationId): OrganizationInvitation
    {
        return $organization->invitations()->findOrFail($invitationId);
    }
}
