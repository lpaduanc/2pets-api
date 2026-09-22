<?php

namespace App\Policies;

use App\Enums\ServiceCategory;
use App\Models\Appointment;
use App\Models\User;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §5/§13.3: só
 * quem atendeu (autor do agendamento) ou o dono da organização decide o que está sendo
 * cobrado antes de a fatura ser paga. Front desk/colega comum nunca edita a conta em
 * aberto — mesmo espírito de separar "concluir agenda" de "decidir conteúdo clínico".
 *
 * Movida de `MedicalRecordPolicy::manageCharges` (§13.3): a conta agora pendura no
 * agendamento, não mais no prontuário.
 *
 * Exceção estrita a INTERNAÇÃO (contrato docs/atendimento-veterinario/
 * 12-modulo-clinico-internacao.md §6.2): plantão muda, e a conta da diária continua sendo
 * da clínica, não só de quem admitiu — um colega da MESMA organização com
 * `medical-records.create` lança diária/item mesmo sem ter admitido o paciente. Nenhum outro
 * `appointment.type` ganha esse terceiro nível: consulta, banho e tosa etc. continuam só
 * autor OU dono da organização.
 */
class AppointmentPolicy
{
    public function manageCharges(User $user, Appointment $appointment): bool
    {
        if ($this->isAuthorOrOrganizationOwner($user, $appointment)) {
            return true;
        }

        return $this->isHospitalizationColleague($user, $appointment, $appointment->professional?->activeOrganizationId());
    }

    /**
     * `POST professional/appointments/{id}/confirm` — Fase 4 do fluxo de agendamento:
     * mesma régua de posse de `manageCharges` (autor OU dono da organização), sem a
     * exceção de colega de internação — confirmar/recusar é decisão de quem vai atender,
     * nunca de um colega de plantão qualquer.
     */
    public function confirm(User $user, Appointment $appointment): bool
    {
        return $this->isAuthorOrOrganizationOwner($user, $appointment);
    }

    /** `POST professional/appointments/{id}/reject` — mesma régua de `confirm()`. */
    public function reject(User $user, Appointment $appointment): bool
    {
        return $this->isAuthorOrOrganizationOwner($user, $appointment);
    }

    private function isAuthorOrOrganizationOwner(User $user, Appointment $appointment): bool
    {
        if ($appointment->professional_id === $user->id) {
            return true;
        }

        $organizationId = $appointment->professional?->activeOrganizationId();

        return $organizationId !== null && $user->ownsOrganization($organizationId);
    }

    private function isHospitalizationColleague(User $user, Appointment $appointment, ?int $authorOrganizationId): bool
    {
        if ($appointment->type !== ServiceCategory::HOSPITALIZATION->value || $authorOrganizationId === null) {
            return false;
        }

        return $user->hasClinicalAccessToOrganization($authorOrganizationId);
    }
}
