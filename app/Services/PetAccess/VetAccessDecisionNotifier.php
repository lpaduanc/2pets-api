<?php

namespace App\Services\PetAccess;

use App\Enums\VetAccessLevel;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\PetVetAccessApproved;
use App\Notifications\PetVetAccessRejected;
use App\Notifications\PetVetAccessRevoked;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisa o VETERINÁRIO das três decisões que o tutor pode tomar sobre um vínculo
 * (`VetAccessGrantService`): aprovar, recusar ou revogar. Extraído para uma classe própria
 * para não fazer `VetAccessGrantService` — dono da regra transacional de nível de acesso —
 * crescer além do que uma classe nova comporta no padrão do projeto.
 *
 * Falha ao notificar nunca desfaz a decisão do tutor, já persistida: loga com contexto e
 * segue. Mesmo padrão de `VetAccessRequestService::notifyTutor()`.
 */
final class VetAccessDecisionNotifier
{
    public function approved(PetVetAccess $access, User $tutor, VetAccessLevel $grantedLevel): void
    {
        $this->safeNotify(
            $access,
            fn () => $access->veterinarian->notify(new PetVetAccessApproved($access, $access->pet, $tutor, $grantedLevel)),
        );
    }

    public function rejected(PetVetAccess $access, User $tutor, ?string $reason): void
    {
        $this->safeNotify(
            $access,
            fn () => $access->veterinarian->notify(new PetVetAccessRejected($access, $access->pet, $tutor, $reason)),
        );
    }

    public function revoked(PetVetAccess $access, User $tutor, ?string $reason): void
    {
        $this->safeNotify(
            $access,
            fn () => $access->veterinarian->notify(new PetVetAccessRevoked($access, $access->pet, $tutor, $reason)),
        );
    }

    private function safeNotify(PetVetAccess $access, callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $failure) {
            Log::error('Failed to dispatch PetVetAccess decision notification', [
                'access_id' => $access->id,
                'vet_id' => $access->veterinarian_id,
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
