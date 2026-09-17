<?php

namespace App\Enums;

/**
 * De onde veio um `PetVetAccess` — só existe para permitir auditoria em lote de grants que
 * NASCERAM concedidos, sem passar pelo aceite normal do tutor
 * (`docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §5).
 *
 * Não confundir com `status`/`access_level`: origem nunca muda depois de criada, mesmo que o
 * nível de acesso mude (`PetVetAccess::supersede()`) — descreve como a linha nasceu, não o
 * estado atual dela.
 */
enum PetVetAccessOrigin: string
{
    /** Fluxo normal: o tutor solicitou/aceitou (`POST /pet-vet-access/*`). */
    case TUTOR_AUTHORIZATION = 'tutor_authorization';

    /**
     * Concedido automaticamente ao vet que cadastrou um paciente novo
     * (`POST professional/appointments/new-patient`) — pré-requisito técnico para o vet poder
     * abrir o próprio prontuário do atendimento, não decisão do tutor.
     */
    case NEW_PATIENT_SELF_GRANT = 'new_patient_self_grant';

    public function label(): string
    {
        return match ($this) {
            self::TUTOR_AUTHORIZATION => 'Autorizado pelo tutor',
            self::NEW_PATIENT_SELF_GRANT => 'Concedido ao cadastrar paciente novo',
        };
    }
}
