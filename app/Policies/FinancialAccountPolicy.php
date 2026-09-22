<?php

namespace App\Policies;

use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/04-contas-bancarias-conciliacao-cartoes.md — "Permissões por
 * papel": ver conta é operacional do dia a dia (qualquer membro ativo); cadastrar/editar conta
 * bancária é dinheiro real da empresa, só de quem possui o escopo (`OWNER`, ou o próprio
 * profissional sem organização).
 */
class FinancialAccountPolicy
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function view(User $user, FinancialAccount $account): bool
    {
        return $this->scope->userCanAccess($account, $user);
    }

    /** Cadastrar conta nova: dono da organização, ou o próprio profissional sem organização. */
    public function create(User $user): bool
    {
        $organizationId = $this->scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }

    public function manage(User $user, FinancialAccount $account): bool
    {
        return $this->ownsScopeOf($user, $account);
    }

    private function ownsScopeOf(User $user, FinancialAccount $account): bool
    {
        if ($account->organization_id !== null) {
            return $user->ownsOrganization($account->organization_id);
        }

        return $account->professional_id === $user->id;
    }
}
