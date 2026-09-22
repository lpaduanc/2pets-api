<?php

namespace App\Policies;

use App\Models\AcquirerSettlement;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/04 — conciliar dinheiro de terceiro (a adquirente) não pode
 * ficar aberto à recepção. Ver depósito é operacional; cadastrar e conciliar é do dono (ou do
 * próprio profissional, sem organização).
 */
class AcquirerSettlementPolicy
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function view(User $user, AcquirerSettlement $settlement): bool
    {
        return $this->scope->userCanAccess($settlement, $user);
    }

    /** Cadastrar o depósito manual: dono da organização, ou o próprio profissional sem organização. */
    public function create(User $user): bool
    {
        $organizationId = $this->scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }

    public function reconcile(User $user, AcquirerSettlement $settlement): bool
    {
        if ($settlement->organization_id !== null) {
            return $user->ownsOrganization($settlement->organization_id);
        }

        return $settlement->professional_id === $user->id;
    }
}
