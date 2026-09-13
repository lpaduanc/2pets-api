<?php

namespace App\DataTransferObjects;

/**
 * Escopo de agregação do dashboard do profissional: quais `users.id` entram nas contagens e
 * o que o front precisa para segmentar a UI por tipo de perfil (item 3 da auditoria de
 * dashboard). Ver `App\Services\Dashboard\ProfessionalScopeResolver`.
 */
final readonly class ProfessionalDashboardScope
{
    /**
     * @param  list<int>  $professionalIds  `users.id` cujos dados entram nas contagens — só o
     *                                      próprio usuário, ou o dono + toda a equipe ativa da
     *                                      organização quando `isClinicAggregate` for `true`.
     * @param  string  $role  Papel Spatie principal do usuário logado (`clinic_owner`,
     *                        `clinic_vet`, `vet_freelancer`, `petshop_owner`, `petshop_staff`).
     * @param  bool  $isClinical  Se este perfil pratica ou responde por ato clínico (prontuário,
     *                            vacina, internação). `false` para petshop puro — a regra do
     *                            CLAUDE.md de que petshop não presta consulta veterinária.
     * @param  bool  $isClinicAggregate  Se as contagens somam a organização inteira (dono que não
     *                                   atende pessoalmente) em vez de só o próprio usuário.
     */
    public function __construct(
        public array $professionalIds,
        public string $role,
        public bool $isClinical,
        public bool $isClinicAggregate,
    ) {}
}
