<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md — "Permissões por papel":
 * ver o saldo/extrato de um cliente pontual é operação de balcão (qualquer membro ativo, quem
 * fecha a venda precisa ver o limite antes de aceitar fiado); o relatório CONSOLIDADO e o
 * ajuste manual são só de quem possui o escopo.
 */
class CompanyClientAccountPolicy
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /** Ver o saldo/extrato de UM cliente: qualquer membro ativo da organização, ou o próprio profissional. */
    public function view(User $user): bool
    {
        return $this->scope->canOperateCounter($user);
    }

    /** Relatório consolidado (`reports/client-balances`, KPI do dashboard): só quem possui o escopo. */
    public function viewAny(User $user): bool
    {
        return $this->ownsScope($user);
    }

    /** Lançamento manual (ajuste/adiantamento) e alteração de `credit_limit`/`allow_credit_sale`. */
    public function manage(User $user): bool
    {
        return $this->ownsScope($user);
    }

    private function ownsScope(User $user): bool
    {
        $organizationId = $this->scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }
}
