<?php

namespace App\Services\Professional;

use App\DataTransferObjects\VetAccessRequestData;
use App\Enums\PetVetAccessOrigin;
use App\Enums\VetAccessLevel;
use App\Exceptions\PetAccess\DuplicateVetAccessRequestException;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\PetAccess\VetAccessRequestService;

/**
 * Concede (ou solicita) o que o vet precisa para atender o paciente que acabou de cadastrar
 * — contrato `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §5, corrigido
 * pelo Achado 2 de `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md`.
 *
 * `write`, nunca `full`: `full` também edita/exclui o cadastro do pet, e o sistema concede o
 * mínimo operacionalmente suficiente quando não há tutor decidindo em tempo real.
 *
 * O grant automático (`accepted` na hora) só existe para tutor **não reivindicado**
 * (`User::isUnclaimed()`): aí não há ninguém para aprovar, e negar travaria o próprio vet de
 * abrir o prontuário do atendimento que ele acabou de criar. Tutor com conta ativa está a um
 * toque de aprovar pelo app — o vínculo nasce `pending`, como qualquer outra solicitação.
 */
final class NewPatientVetAccessGrantor
{
    private const string PENDING_REQUEST_MESSAGE = 'Solicitação automática gerada ao registrar um agendamento para você como paciente novo.';

    public function __construct(
        private readonly VetAccessRequestService $vetAccessRequestService,
    ) {}

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
     * `existing_pet_id` para um paciente que ele já atendia) — nesse caso nem o grant
     * automático nem o pedido pendente fazem sentido.
     */
    private function grantPetAccess(User $veterinarian, Pet $pet): void
    {
        if ($this->hasActiveGrant($veterinarian, $pet)) {
            return;
        }

        if ($pet->user->isUnclaimed()) {
            $this->grantAutomaticAccess($veterinarian, $pet);

            return;
        }

        $this->requestPendingAccess($veterinarian, $pet);
    }

    private function hasActiveGrant(User $veterinarian, Pet $pet): bool
    {
        return PetVetAccess::forPet($pet->id)
            ->forVet($veterinarian->id)
            ->active()
            ->exists();
    }

    private function grantAutomaticAccess(User $veterinarian, Pet $pet): void
    {
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

    /**
     * Reaproveita o mesmo handshake de `POST pet-vet-access/request` (pedido pendente +
     * notificação ao tutor + dedup por índice único). Duplicidade (já pendente ou já coberto
     * por um acesso vivo) não é erro aqui — só significa que não há nada novo a fazer.
     */
    private function requestPendingAccess(User $veterinarian, Pet $pet): void
    {
        $data = new VetAccessRequestData(
            petId: $pet->id,
            tutorCpf: null,
            petData: [],
            requestedAccessLevel: VetAccessLevel::WRITE,
            message: self::PENDING_REQUEST_MESSAGE,
        );

        try {
            $this->vetAccessRequestService->request($veterinarian, $data, PetVetAccessOrigin::NEW_PATIENT_PENDING_REQUEST);
        } catch (DuplicateVetAccessRequestException) {
            // Já pendente ou já coberto por um acesso vivo — nada novo a fazer.
        }
    }
}
