<?php

namespace App\Services\Dashboard;

use App\DataTransferObjects\ProfessionalDashboardScope;
use App\Models\OrganizationMember;
use App\Models\User;

/**
 * Decide, para o dashboard do profissional, QUAIS `users.id` entram nas contagens e como o
 * front deve segmentar a tela (item 2 e item 3 da auditoria de `/professional/dashboard/stats`).
 *
 * `clinic_owner` que não atende pessoalmente via mostrando "0 consultas hoje" com a clínica
 * cheia era o P0 de produto: `appointments`/`invoices`/`services`/`inventories`.`professional_id`
 * é sempre `users.id` de QUEM CRIOU a linha (ver `AppointmentController::store`), então o
 * `clinic_vet` que atende em nome da clínica grava as próprias linhas sob o próprio id, nunca
 * sob o do dono. Agregar por dono sozinho sempre mostraria só a fatia dele.
 *
 * A tabela `organizations`/`organization_members` (Fase "split Pessoa/Organização") já modela
 * exatamente quem faz parte de qual clínica — não foi preciso schema novo. As colunas
 * `organization_id` recém-adicionadas às tabelas comerciais (migration
 * `2026_09_14_100000_add_organization_id_to_commercial_tables`) NÃO servem para isto ainda:
 * nenhum controller de escrita (`AppointmentController`, `InvoiceController`, `ServiceController`,
 * `ProductController`) as preenche na criação, então uma consulta criada hoje por um
 * `clinic_vet` fica com `organization_id` nulo até existir um backfill contínuo. Agregar pela
 * lista de `user_id` de `organization_members` funciona para dado antigo e novo igualmente,
 * por isso é o caminho escolhido aqui em vez de `WHERE organization_id = ?`.
 */
final class ProfessionalScopeResolver
{
    /**
     * Papéis Spatie que descrevem quem atua na tela do profissional. Usado só para escolher
     * qual é o "papel principal" quando o usuário carrega mais de um (caso raro).
     *
     * @var list<string>
     */
    private const PROFESSIONAL_ROLES = [
        'clinic_owner',
        'clinic_vet',
        'vet_freelancer',
        'petshop_owner',
        'petshop_staff',
    ];

    /**
     * Papéis que praticam ou respondem por ato clínico — determina quais blocos do dashboard
     * fazem sentido (prontuário, internação, acesso a pet) e quais não (petshop puro).
     *
     * @var list<string>
     */
    private const CLINICAL_ROLES = ['clinic_owner', 'clinic_vet', 'vet_freelancer'];

    public function resolve(User $user): ProfessionalDashboardScope
    {
        $role = $this->primaryRole($user);

        if ($role === 'clinic_owner') {
            return $this->resolveClinicOwnerScope($user, $role);
        }

        return new ProfessionalDashboardScope(
            professionalIds: [$user->id],
            role: $role,
            isClinical: in_array($role, self::CLINICAL_ROLES, true),
            isClinicAggregate: false,
        );
    }

    private function primaryRole(User $user): string
    {
        foreach (self::PROFESSIONAL_ROLES as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return $user->getRoleNames()->first() ?? 'unknown';
    }

    /**
     * Dono de mais de uma organização é um caso ambíguo já documentado em
     * `CommercialOrganizationBackfillService` (dado legado não registra a qual negócio uma
     * linha pertence). O mesmo cuidado vale aqui: sem um seletor de organização na UI hoje,
     * agregar a organização "errada" seria pior do que cair para a visão pessoal — por isso o
     * fallback abaixo é pessoal, não a primeira organização encontrada.
     */
    private function resolveClinicOwnerScope(User $user, string $role): ProfessionalDashboardScope
    {
        $organizationId = $this->soleOwnedOrganizationId($user);

        if ($organizationId === null) {
            return new ProfessionalDashboardScope([$user->id], $role, true, false);
        }

        $memberIds = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->pluck('user_id')
            ->all();

        return new ProfessionalDashboardScope(
            professionalIds: array_values(array_unique([...$memberIds, $user->id])),
            role: $role,
            isClinical: true,
            isClinicAggregate: true,
        );
    }

    private function soleOwnedOrganizationId(User $user): ?int
    {
        $organizationIds = OrganizationMember::query()
            ->where('user_id', $user->id)
            ->where('role', OrganizationMember::ROLE_OWNER)
            ->where('is_active', true)
            ->pluck('organization_id');

        return $organizationIds->count() === 1 ? (int) $organizationIds->first() : null;
    }
}
