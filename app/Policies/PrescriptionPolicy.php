<?php

namespace App\Policies;

use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\User;

/**
 * Mesmo triângulo de `MedicalRecordPolicy`: tutor dono do pet, o profissional autor, ou vet
 * com `PetVetAccess` ativo. Contrato docs/atendimento-veterinario/03-contrato-receituario.md
 * §1/§6: prescrição NÃO EMITIDA nunca aparece para quem não é o autor — mesma regra do
 * rascunho de prontuário.
 */
class PrescriptionPolicy
{
    /**
     * Registro único. O autor vê sempre, emitida ou não — é o próprio trabalho dele. Tutor e
     * vet com acesso ativo só veem prescrição já EMITIDA (rascunho de outro profissional
     * nunca é documento clínico visível).
     */
    public function view(User $user, Prescription $prescription): bool
    {
        if ($prescription->professional_id === $user->id) {
            return true;
        }

        if (! $prescription->isIssued()) {
            return false;
        }

        $pet = Pet::find($prescription->pet_id);
        if ($pet === null) {
            return false;
        }

        if ($pet->user_id === $user->id) {
            return true;
        }

        return $this->hasActiveVetAccess($user->id, $pet->id);
    }

    /**
     * Gate de `GET pets/{pet}/prescriptions`: quem pode ao menos abrir a listagem
     * compartilhada deste pet. Autor de QUALQUER prescrição do pet passa aqui mesmo sem
     * acesso ativo hoje — o filtro fino de QUAIS linhas ele vê é
     * `restrictedToOwnPrescriptions()`, aplicado pelo controller na query.
     */
    public function viewAny(User $user, Pet $pet): bool
    {
        if ($pet->user_id === $user->id) {
            return true;
        }

        if ($this->hasActiveVetAccess($user->id, $pet->id)) {
            return true;
        }

        return $this->hasAuthoredAnyPrescriptionFor($user, $pet);
    }

    /**
     * `true` quando o único fundamento de acesso do requisitante é ter escrito alguma
     * prescrição deste pet (sem ser o tutor, sem acesso ativo hoje) — a listagem, nesse caso,
     * precisa ficar restrita ao que ele mesmo prescreveu, nunca ao histórico inteiro do pet.
     */
    public function restrictedToOwnPrescriptions(User $user, Pet $pet): bool
    {
        if ($pet->user_id === $user->id) {
            return false;
        }

        return ! $this->hasActiveVetAccess($user->id, $pet->id);
    }

    private function hasAuthoredAnyPrescriptionFor(User $user, Pet $pet): bool
    {
        return Prescription::query()
            ->where('pet_id', $pet->id)
            ->where('professional_id', $user->id)
            ->exists();
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
