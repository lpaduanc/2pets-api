<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query object: "quais faturas este profissional pode ver na própria lista" — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §5/§11 item 3.
 *
 * Achado de bug corrigido: `InvoiceController` filtrava só por `professional_id`, então um
 * colega de clínica com a mesma `organization_id` recebia 404 numa fatura que deveria poder
 * cobrar. Extraído para reuso, mesmo espírito de `ProfessionalClientsQuery`.
 *
 * Um colega comum (não dono da organização) nunca vê rascunho de outro autor — só o autor
 * ou o dono da organização veem `status = draft`.
 */
final class VisibleInvoicesQuery
{
    public function forUser(User $user): Builder
    {
        $ownedOrganizationIds = $user->ownedOrganizations()->pluck('organizations.id');
        $memberOrganizationIds = $user->activeOrganizationMemberships()->pluck('organization_id');

        return Invoice::query()->where(
            fn (Builder $query) => $query
                ->where('professional_id', $user->id)
                ->orWhereIn('organization_id', $ownedOrganizationIds)
                ->orWhere(
                    fn (Builder $inner) => $inner
                        ->whereIn('organization_id', $memberOrganizationIds)
                        ->where('status', '!=', InvoiceStatus::DRAFT->value)
                )
        );
    }
}
