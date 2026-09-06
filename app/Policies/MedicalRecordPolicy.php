<?php

namespace App\Policies;

use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;

class MedicalRecordPolicy
{
    /**
     * Sigilo profissional (CFMV):
     *   - Pet owner (tutor) always sees records of their pet.
     *   - The vet who authored the record sees it.
     *   - Another vet with an active PetVetAccess (authorized by the tutor) sees it.
     *
     * Colleagues at the same clinic do NOT get access by default — access is per pet,
     * granted explicitly by the tutor.
     */
    public function view(User $user, MedicalRecord $record): bool
    {
        if ($record->professional_id === $user->id) {
            return true;
        }

        $pet = Pet::find($record->pet_id);
        if (! $pet) {
            return false;
        }

        if ($pet->user_id === $user->id) {
            return true;
        }

        return $this->hasActiveVetAccess($user->id, $pet->id);
    }

    public function create(User $user, Pet $pet): bool
    {
        return $this->hasActiveVetAccess($user->id, $pet->id);
    }

    /**
     * Only the vet who authored the record can edit/delete it.
     * Altering a colleague's record would break sigilo and prontuário integrity.
     */
    public function update(User $user, MedicalRecord $record): bool
    {
        return $record->professional_id === $user->id;
    }

    public function delete(User $user, MedicalRecord $record): bool
    {
        return $record->professional_id === $user->id;
    }

    private function hasActiveVetAccess(int $userId, int $petId): bool
    {
        return PetVetAccess::query()
            ->where('veterinarian_id', $userId)
            ->where('pet_id', $petId)
            ->active()
            ->exists();
    }
}
