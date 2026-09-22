<?php

namespace App\Services\Insights;

use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Regra de negócio 5 da spec: "colaborador nunca vê produtividade de outro". `bi.view.any`
 * (dono/gestor) pode consultar qualquer vínculo; quem só tem `bi.view.own` (o middleware de
 * rota já garante que o usuário tem pelo menos um dos dois) fica travado nos PRÓPRIOS
 * vínculos ativos, mesmo que peça outro id explicitamente — nunca um 200 com dado alheio.
 */
final class ProductivityAuthorization
{
    /**
     * @param  list<int>  $requestedEmployeeIds
     * @return list<int> vazio = "sem restrição" (só devolvido para quem tem `bi.view.any`)
     */
    public function resolveEmployeeIds(User $user, array $requestedEmployeeIds): array
    {
        $ownMembershipIds = $this->ownMembershipIds($user);

        if ($requestedEmployeeIds === []) {
            return $user->can('bi.view.any') ? [] : $ownMembershipIds;
        }

        $this->guardAgainstForeignEmployees($user, $requestedEmployeeIds, $ownMembershipIds);

        return $requestedEmployeeIds;
    }

    /** @return list<int> */
    public function ownMembershipIds(User $user): array
    {
        return $user->activeOrganizationMemberships()->pluck('id')->all();
    }

    /**
     * @param  list<int>  $requestedEmployeeIds
     * @param  list<int>  $ownMembershipIds
     */
    private function guardAgainstForeignEmployees(User $user, array $requestedEmployeeIds, array $ownMembershipIds): void
    {
        $requestsOnlyOwnData = Collection::make($requestedEmployeeIds)->diff($ownMembershipIds)->isEmpty();

        if ($requestsOnlyOwnData || $user->can('bi.view.any')) {
            return;
        }

        throw new AccessDeniedHttpException('Você não pode ver a produtividade de outro colaborador.');
    }
}
