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
     *   - Pet owner (tutor) always sees FINALIZED records of their pet.
     *   - The vet who authored the record sees it, draft or finalized.
     *   - Another vet with an active PetVetAccess (authorized by the tutor) sees FINALIZED
     *     records only.
     *
     * Rascunho é anotação de trabalho do autor, nunca documento — nenhuma rota, tutor ou
     * colega, pode ver um `medical_records.status = draft` alheio (contrato
     * docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §6).
     *
     * Colleagues at the same clinic do NOT get access by default — access is per pet,
     * granted explicitly by the tutor.
     */
    public function view(User $user, MedicalRecord $record): bool
    {
        if ($record->professional_id === $user->id) {
            return true;
        }

        if ($record->isDraft()) {
            return false;
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
     * Only the vet who authored the record can edit it, and only while it is still a
     * draft — once `finalized` the clinical fields are immutable (contrato §1.5/§7):
     * correction is `MedicalRecordAddendum`, never a rewrite.
     */
    public function update(User $user, MedicalRecord $record): bool
    {
        return $record->professional_id === $user->id && $record->isDraft();
    }

    /**
     * BUG corrigido (contrato §7): antes só olhava `professional_id`, permitindo apagar um
     * prontuário finalizado — documento legal que a Res. CFMV exige preservar. `delete` só
     * vale para descartar um rascunho abandonado.
     */
    public function delete(User $user, MedicalRecord $record): bool
    {
        return $record->professional_id === $user->id && $record->isDraft();
    }

    /**
     * Adendo é uma entrada NOVA, não uma edição — mas continua sendo autoria exclusiva do
     * profissional que assinou o prontuário (mesma régua da tabela de papéis do
     * docs/atendimento-veterinario/00-dominio-e-escopo.md §2: colega com `PetVetAccess` só
     * visualiza, nunca cria/edita). Só faz sentido sobre um prontuário já `finalized`.
     */
    public function addAddendum(User $user, MedicalRecord $record): bool
    {
        return $record->professional_id === $user->id && $record->isFinalized();
    }

    /**
     * Anexar evidência (exame, foto) é permitido em qualquer status — ao contrário dos
     * campos clínicos, um anexo não reescreve o que já foi dito. Remover, porém, só
     * enquanto rascunho (`removeAttachment`) — depois de finalizado o anexo faz parte do
     * documento entregue ao tutor.
     */
    public function manageAttachment(User $user, MedicalRecord $record): bool
    {
        return $record->professional_id === $user->id;
    }

    public function removeAttachment(User $user, MedicalRecord $record): bool
    {
        return $record->professional_id === $user->id && $record->isDraft();
    }

    /**
     * `POST /medical-records/{id}/apply-to-pet` e `GET /medical-records/{id}/pet-data-diff` —
     * contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §D. Só o
     * TUTOR promove `reported_pet_data` ao cadastro — nenhum vet, nem com `full`: quem decide
     * o que é verdade no cadastro do PRÓPRIO pet é sempre o titular do dado.
     */
    public function applyToPet(User $user, MedicalRecord $record): bool
    {
        $pet = Pet::find($record->pet_id);

        return $pet !== null && $pet->user_id === $user->id;
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
