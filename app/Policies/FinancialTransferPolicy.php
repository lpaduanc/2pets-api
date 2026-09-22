<?php

namespace App\Policies;

use App\Models\FinancialTransfer;
use App\Models\User;
use App\Policies\Concerns\AuthorizesFinancialOwnership;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md — transferir dinheiro entre
 * contas da própria clínica é só de `OWNER` (ou o próprio profissional, sem organização).
 */
class FinancialTransferPolicy
{
    use AuthorizesFinancialOwnership;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->ownsScope($user, $this->scope);
    }

    public function view(User $user, FinancialTransfer $transfer): bool
    {
        return $this->ownsScopeOf($user, $transfer->organization_id, $transfer->professional_id);
    }

    public function create(User $user): bool
    {
        return $this->ownsScope($user, $this->scope);
    }
}
