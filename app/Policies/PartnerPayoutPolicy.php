<?php

namespace App\Policies;

use App\Models\PartnerPayout;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de
 * negócio 7 — repasse a parceiro é dinheiro real saindo da clínica, mesma régua de
 * `FinancialAccountPolicy`: só o dono (ou o próprio profissional sem organização).
 */
class PartnerPayoutPolicy
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function create(User $user): bool
    {
        $organizationId = $this->scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }

    public function manage(User $user, PartnerPayout $payout): bool
    {
        if ($payout->organization_id !== null) {
            return $user->ownsOrganization($payout->organization_id);
        }

        return $payout->professional_id === $user->id;
    }
}
