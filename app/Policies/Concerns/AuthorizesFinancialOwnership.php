<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Regra compartilhada das policies do financeiro (doc 02): só `OWNER` da organização, ou o
 * próprio profissional sem organização, mexe em plano de contas, lançamento e transferência —
 * é a visão financeira consolidada da empresa, nunca operação de balcão.
 */
trait AuthorizesFinancialOwnership
{
    public function ownsScope(User $user, CommercialScopeResolver $scope): bool
    {
        $organizationId = $scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }

    public function ownsScopeOf(User $user, ?int $organizationId, ?int $professionalId): bool
    {
        if ($organizationId !== null) {
            return $user->ownsOrganization($organizationId);
        }

        return $professionalId === $user->id;
    }
}
