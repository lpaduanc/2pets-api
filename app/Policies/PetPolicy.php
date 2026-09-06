<?php

namespace App\Policies;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;

class PetPolicy
{
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

        if ($requiredLevel !== null) {
            $query->where('access_level', $requiredLevel->value);
        }

        return $query->exists();
    }
}
