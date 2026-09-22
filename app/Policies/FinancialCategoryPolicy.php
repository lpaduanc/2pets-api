<?php

namespace App\Policies;

use App\Models\FinancialCategory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesFinancialOwnership;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md — "Permissões por papel":
 * ver/editar plano de contas é a visão financeira consolidada da empresa, só de `OWNER` (ou o
 * próprio profissional, sem organização). Diferente do PDV/venda, que é balcão aberto a todo
 * `OrganizationRole`.
 */
class FinancialCategoryPolicy
{
    use AuthorizesFinancialOwnership;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->ownsScope($user, $this->scope);
    }

    public function view(User $user, FinancialCategory $category): bool
    {
        return $this->ownsScopeOf($user, $category->organization_id, $category->professional_id);
    }

    public function create(User $user): bool
    {
        return $this->ownsScope($user, $this->scope);
    }

    public function manage(User $user, FinancialCategory $category): bool
    {
        return $this->ownsScopeOf($user, $category->organization_id, $category->professional_id);
    }
}
