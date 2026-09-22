<?php

namespace App\Services\Organization;

use App\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * Anexa `team_size` (contagem de membros ativos da organização que este `User` possui) a
 * uma query de `User` já montada — sem N+1: é UMA subquery escalar por linha, dentro da
 * MESMA consulta SQL (`addSelect`), não uma query separada por profissional.
 *
 * Usado pela busca pública (`ProfessionalSearchCardResource`/`ProfessionalSearchResource`)
 * para o app distinguir "conta unipessoal" de "estabelecimento com equipe" — Fase 2 do
 * fluxo de agendamento.
 */
final class TeamSizeQuery
{
    public static function applyTo(Builder $query): Builder
    {
        return $query->addSelect([
            'team_size' => OrganizationMember::query()
                ->selectRaw('count(*)')
                ->where('is_active', true)
                ->whereIn('organization_id', function ($ownedOrganizations): void {
                    $ownedOrganizations->select('organization_id')
                        ->from('organization_members')
                        ->whereColumn('organization_members.user_id', 'users.id')
                        ->where('organization_members.role', OrganizationMember::ROLE_OWNER)
                        ->where('organization_members.is_active', true);
                }),
        ]);
    }
}
