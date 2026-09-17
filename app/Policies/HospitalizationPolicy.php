<?php

namespace App\Policies;

use App\Models\Hospitalization;
use App\Models\User;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §6.2.
 *
 * Bug corrigido por esta policy: `HospitalizationController` restringia `index`/`show`/
 * `update` a `professional_id = usuário autenticado`, sem nenhuma verificação de
 * organização — um colega `clinic_vet` da mesma clínica não conseguia nem LER a internação
 * admitida por outro colega. Terceiro nível de autorização, reaproveitando peças que já
 * existem (`User::hasClinicalAccessToOrganization`, `activeOrganizationId`):
 *
 *   autor da internação
 *     OU
 *   ( usuário tem `medical-records.create` E é membro ativo da mesma organização
 *     do profissional que admitiu )
 *
 * `clinic_owner` nunca tem `medical-records.create` — continua sem escrever registro
 * clínico, mesma regra de "conta-clínica não é autora clínica" já valendo no projeto.
 * `vet_freelancer` sem organização (`activeOrganizationId() === null`) só é acessível pelo
 * próprio autor — sem "colega de plantão" nenhum, reflexo real desse modelo de negócio.
 */
class HospitalizationPolicy
{
    /** `GET hospitalizations`/`GET hospitalizations/{id}`. */
    public function view(User $user, Hospitalization $hospitalization): bool
    {
        return $this->hasClinicalAccess($user, $hospitalization);
    }

    /**
     * `PUT hospitalizations/{id}` — inclui fechar a estadia (alta/transferência/óbito): o
     * colega de plantão presente no momento da alta nem sempre é quem admitiu.
     */
    public function update(User $user, Hospitalization $hospitalization): bool
    {
        return $this->hasClinicalAccess($user, $hospitalization);
    }

    /**
     * `DELETE hospitalizations/{id}` — descarte do registro inteiro de admissão, mais grave
     * que editar um campo: fica restrito a autor ou dono da organização, sem estender ao
     * colega comum (mesma régua de `InvoicePolicy::editDraft`).
     */
    public function delete(User $user, Hospitalization $hospitalization): bool
    {
        if ($user->id === $hospitalization->professional_id) {
            return true;
        }

        $organizationId = $hospitalization->professional?->activeOrganizationId();

        return $organizationId !== null && $user->ownsOrganization($organizationId);
    }

    /** `POST hospitalizations/{id}/progress-notes`. */
    public function writeProgressNote(User $user, Hospitalization $hospitalization): bool
    {
        return $this->hasClinicalAccess($user, $hospitalization);
    }

    /** `POST hospitalizations/{id}/care-logs`. */
    public function writeCareLog(User $user, Hospitalization $hospitalization): bool
    {
        return $this->hasClinicalAccess($user, $hospitalization);
    }

    private function hasClinicalAccess(User $user, Hospitalization $hospitalization): bool
    {
        if ($user->id === $hospitalization->professional_id) {
            return true;
        }

        $organizationId = $hospitalization->professional?->activeOrganizationId();

        return $organizationId !== null && $user->hasClinicalAccessToOrganization($organizationId);
    }
}
