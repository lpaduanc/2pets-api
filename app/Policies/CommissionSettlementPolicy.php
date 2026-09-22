<?php

namespace App\Policies;

use App\Models\CommissionSettlement;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de
 * negócio 5: "funcionário só vê a própria comissão; dono/gerente vê todas". Vazamento de
 * comissão de colega é passivo trabalhista, não só bug técnico — ver o comentário da spec.
 *
 * `me/commissions` (extrato pessoal) não passa por esta policy: a query já nasce filtrada
 * pelos próprios `organization_members` do usuário autenticado, o mesmo padrão de `me/quotes`.
 */
class CommissionSettlementPolicy
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /** Abrir a prévia e fechar um período: só o dono — mesmo motivo de `manage()`. */
    public function create(User $user): bool
    {
        $organizationId = $this->scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }

    /** Ver o fechamento: o dono da organização, ou o próprio funcionário do fechamento. */
    public function view(User $user, CommissionSettlement $settlement): bool
    {
        if ($this->isOwner($user, $settlement)) {
            return true;
        }

        return $settlement->staff?->user_id === $user->id;
    }

    /** Fechar período e marcar como pago: só o dono — mexe em dinheiro real da equipe. */
    public function manage(User $user, CommissionSettlement $settlement): bool
    {
        return $this->isOwner($user, $settlement);
    }

    private function isOwner(User $user, CommissionSettlement $settlement): bool
    {
        if ($settlement->organization_id !== null) {
            return $user->ownsOrganization($settlement->organization_id);
        }

        return $settlement->professional_id === $user->id;
    }
}
