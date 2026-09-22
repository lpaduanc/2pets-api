<?php

namespace App\Services\Professional;

use App\Enums\ClientPortalState;
use App\Models\RegistrationContinuationToken;
use App\Models\User;

/**
 * `GET professional/clients/{client}/portal-status` — contrato
 * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md` item 4. Nenhuma coluna nova:
 * o estado é derivado de `User::isUnclaimed()` mais a existência de algum convite emitido.
 */
final class ClientPortalStatusService
{
    public function resolve(User $client): ClientPortalState
    {
        if (! $client->isUnclaimed()) {
            return ClientPortalState::ACTIVE;
        }

        return $this->hasAnyInvitation($client)
            ? ClientPortalState::INVITED
            : ClientPortalState::NO_ACCOUNT;
    }

    /**
     * Qualquer convite já emitido conta, mesmo expirado/consumido — "convidado" descreve o
     * histórico do vínculo, não se o link ainda funciona hoje.
     */
    private function hasAnyInvitation(User $client): bool
    {
        return RegistrationContinuationToken::query()
            ->where('user_id', $client->id)
            ->exists();
    }
}
