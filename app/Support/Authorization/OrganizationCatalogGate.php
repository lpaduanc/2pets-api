<?php

namespace App\Support\Authorization;

use App\Models\User;

/**
 * Regra de autorização compartilhada pelos catálogos administrativos introduzidos pelas
 * specs 13/14/15/16 (`immunization_products`/`immunization_protocols`, `exam_types`,
 * `document_templates`, `appointment_types`): todos têm a mesma forma
 * (`organization_id` nullable = catálogo global) e a mesma regra de quem pode escrever.
 *
 * `organization_id = null` é catálogo global da plataforma (seedado/curado) — nunca editável
 * via API nesta fatia, mesmo por quem tem outras permissões (evita uma clínica sobrescrever o
 * calendário vacinal sugerido de todas as outras). Escrita exige organização ativa: dono da
 * organização OU membro com acesso clínico (`User::hasClinicalAccessToOrganization`) — mesma
 * régua já usada por `HospitalizationPolicy`. Configuração de catálogo é ato administrativo,
 * não clínico, então cargos não-veterinários do dono continuam podendo gerenciar.
 */
final class OrganizationCatalogGate
{
    public static function canManage(User $user, ?int $organizationId): bool
    {
        if ($organizationId === null) {
            return false;
        }

        return $user->ownsOrganization($organizationId) || $user->hasClinicalAccessToOrganization($organizationId);
    }
}
