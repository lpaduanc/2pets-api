<?php

namespace App\Policies;

use App\Models\PetVetAccess;
use App\Models\User;

/**
 * Quem manda no vínculo é o TUTOR DONO DO PET — nunca o veterinário, nem quando é ele o
 * titular do acesso.
 *
 * Isso é o que impede o downgrade de virar escalonamento de privilégio: se o vet pudesse
 * chamar `accept` ou `changeLevel`, ele escolheria o próprio nível e a inversão da regra de
 * negócio (tutor decide) perderia o sentido. Todo endpoint que decide ou altera nível passa
 * por aqui; nenhuma checagem inline de ownership em controller.
 *
 * Depende de `pet` carregado — os call sites usam `PetVetAccess::with('pet')`.
 */
class PetVetAccessPolicy
{
    /** Aceitar ou recusar uma solicitação pendente. */
    public function respond(User $user, PetVetAccess $access): bool
    {
        return $this->isPetOwner($user, $access);
    }

    /** Retirar um acesso já concedido. */
    public function revoke(User $user, PetVetAccess $access): bool
    {
        return $this->isPetOwner($user, $access);
    }

    /** Subir ou descer o nível de um acesso já concedido, sem nova solicitação. */
    public function changeLevel(User $user, PetVetAccess $access): bool
    {
        return $this->isPetOwner($user, $access);
    }

    private function isPetOwner(User $user, PetVetAccess $access): bool
    {
        return $access->pet?->user_id === $user->id;
    }
}
