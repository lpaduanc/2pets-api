<?php

namespace App\Policies;

use App\Models\FinancialEntry;
use App\Models\User;
use App\Policies\Concerns\AuthorizesFinancialOwnership;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md — ver/lançar/baixar
 * lançamento manual é só de `OWNER` (ou o próprio profissional, sem organização).
 *
 * Exceção deliberada (documentada na spec, não implementada aqui): o atalho "editar venda"/
 * "editar compra" a partir de um lançamento AUTOMÁTICO segue a policy da tela de origem
 * (`SalePolicy`), não esta.
 */
class FinancialEntryPolicy
{
    use AuthorizesFinancialOwnership;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->ownsScope($user, $this->scope);
    }

    public function view(User $user, FinancialEntry $entry): bool
    {
        return $this->ownsScopeOf($user, $entry->organization_id, $entry->professional_id);
    }

    public function create(User $user): bool
    {
        return $this->ownsScope($user, $this->scope);
    }

    public function manage(User $user, FinancialEntry $entry): bool
    {
        return $this->ownsScopeOf($user, $entry->organization_id, $entry->professional_id);
    }
}
