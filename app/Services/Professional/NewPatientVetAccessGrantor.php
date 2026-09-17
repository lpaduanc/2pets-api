<?php

namespace App\Services\Professional;

use App\Enums\PetVetAccessOrigin;
use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\ProfessionalClient;
use App\Models\User;

/**
 * Concede automaticamente o que o vet precisa para atender o paciente que acabou de cadastrar
 * — contrato `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §5.
 *
 * `write`, nunca `full`: `full` também edita/exclui o cadastro do pet, e não há tutor real
 * para decidir isso ainda (mesma semântica de `VetAccessLevel`, que documenta que quem escolhe
 * o nível é sempre o tutor — aqui não há tutor decidindo, então o sistema concede o mínimo
 * operacionalmente suficiente).
 */
final class NewPatientVetAccessGrantor
{
    public function grant(User $veterinarian, Pet $pet): void
    {
        $this->linkAsClient($veterinarian, $pet->user);
        $this->grantPetAccess($veterinarian, $pet);
    }

    /**
     * Idempotente: um tutor já vinculado (ex.: reaproveitando `existing_pet_id` de um cliente
     * antigo) não gera linha duplicada — mesmo padrão de `ClientProvisioningService`.
     */
    private function linkAsClient(User $veterinarian, User $tutor): void
    {
        $link = ProfessionalClient::withTrashed()->firstOrNew([
            'professional_id' => $veterinarian->id,
            'client_id' => $tutor->id,
        ]);

        if ($link->trashed()) {
            $link->restore();

            return;
        }

        if (! $link->exists) {
            $link->save();
        }
    }

    /**
     * Não duplica se o vet já tiver um grant ativo para este pet (mesmo tutor reaproveitando
     * `existing_pet_id` para um paciente que ele já atendia).
     */
    private function grantPetAccess(User $veterinarian, Pet $pet): void
    {
        $hasActiveGrant = PetVetAccess::forPet($pet->id)
            ->forVet($veterinarian->id)
            ->active()
            ->exists();

        if ($hasActiveGrant) {
            return;
        }

        PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $veterinarian->id,
            'granted_by' => $veterinarian->id,
            'origin' => PetVetAccessOrigin::NEW_PATIENT_SELF_GRANT,
            'access_level' => VetAccessLevel::WRITE,
            'requested_access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'requested_at' => now(),
            'responded_at' => now(),
            'granted_at' => now(),
        ]);
    }
}
