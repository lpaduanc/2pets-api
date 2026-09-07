<?php

namespace App\Policies;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;

class PetPolicy
{
    /**
     * Class-level ability: looking up someone else's pet by an exact tutor identifier
     * (CPF/microchip) so the vet can request access to it. Restricted to veterinarians —
     * this endpoint reads tutor PII (name) of users the requester has no relationship with,
     * so it must never be reachable by a tutor account.
     */
    public function search(User $user): bool
    {
        return $user->isVeterinarian();
    }

    /**
     * Class-level ability: opening a consent handshake for a pet the vet does not own.
     */
    public function requestAccess(User $user): bool
    {
        return $user->isVeterinarian();
    }

    /**
     * Tutor (owner) or vet with any active access can view the pet.
     */
    public function view(User $user, Pet $pet): bool
    {
        if ($pet->user_id === $user->id) {
            return true;
        }

        return $this->hasActiveVetAccess($user->id, $pet->id);
    }

    /**
     * Owner always; vet only when the grant is FULL (unlimited access).
     * WRITE grant is limited to medical records — the pet record itself
     * (name, breed, microchip, photo, etc.) stays owner-or-full.
     */
    public function update(User $user, Pet $pet): bool
    {
        if ($pet->user_id === $user->id) {
            return true;
        }

        return $this->hasActiveVetAccess($user->id, $pet->id, VetAccessLevel::FULL);
    }

    public function delete(User $user, Pet $pet): bool
    {
        if ($pet->user_id === $user->id) {
            return true;
        }

        return $this->hasActiveVetAccess($user->id, $pet->id, VetAccessLevel::FULL);
    }

    private function hasActiveVetAccess(int $userId, int $petId, ?VetAccessLevel $requiredLevel = null): bool
    {
        $query = PetVetAccess::query()
            ->where('veterinarian_id', $userId)
            ->where('pet_id', $petId)
            ->active();

        // Níveis são cumulativos: exigir FULL aceita FULL e qualquer coisa acima dele.
        // A ordem vive em VetAccessLevel, nunca numa lista repetida aqui.
        if ($requiredLevel !== null) {
            $query->whereIn('access_level', VetAccessLevel::valuesAtLeast($requiredLevel));
        }

        return $query->exists();
    }
}
