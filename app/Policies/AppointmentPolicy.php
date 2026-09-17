<?php

namespace App\Policies;

use App\Enums\ServiceCategory;
use App\Models\Appointment;
use App\Models\User;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §5/§13.3: só
 * quem atendeu (autor do agendamento) ou o dono da organização decide o que está sendo
 * cobrado antes de a fatura ser paga. Front desk/colega comum nunca edita a comanda em
 * aberto — mesmo espírito de separar "concluir agenda" de "decidir conteúdo clínico".
 *
 * Movida de `MedicalRecordPolicy::manageCharges` (§13.3): a comanda agora pendura no
 * agendamento, não mais no prontuário.
 *
 * Exceção estrita a INTERNAÇÃO (contrato docs/atendimento-veterinario/
 * 12-modulo-clinico-internacao.md §6.2): plantão muda, e a comanda da diária continua sendo
 * da clínica, não só de quem admitiu — um colega da MESMA organização com
 * `medical-records.create` lança diária/item mesmo sem ter admitido o paciente. Nenhum outro
 * `appointment.type` ganha esse terceiro nível: consulta, banho e tosa etc. continuam só
 * autor OU dono da organização.
 */
class AppointmentPolicy
{
    public function manageCharges(User $user, Appointment $appointment): bool
    {
        if ($appointment->professional_id === $user->id) {
            return true;
        }

        $authorOrganizationId = $appointment->professional?->activeOrganizationId();

        if ($authorOrganizationId !== null && $user->ownsOrganization($authorOrganizationId)) {
            return true;
        }

        return $this->isHospitalizationColleague($user, $appointment, $authorOrganizationId);
    }

    private function isHospitalizationColleague(User $user, Appointment $appointment, ?int $authorOrganizationId): bool
    {
        if ($appointment->type !== ServiceCategory::HOSPITALIZATION->value || $authorOrganizationId === null) {
            return false;
        }

        return $user->hasClinicalAccessToOrganization($authorOrganizationId);
    }
}
