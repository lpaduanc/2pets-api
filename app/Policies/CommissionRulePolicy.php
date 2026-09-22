<?php

namespace App\Policies;

use App\Models\CommissionRule;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md — cadastrar
 * regra de comissão é dinheiro real do funcionário: só o dono da organização (ou o próprio
 * profissional sem organização, comissionando um parceiro sem vínculo formal).
 */
class CommissionRulePolicy
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        $organizationId = $this->scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }

    public function manage(User $user, CommissionRule $rule): bool
    {
        if ($rule->organization_id !== null) {
            return $user->ownsOrganization($rule->organization_id);
        }

        return $rule->professional_id === $user->id;
    }
}
