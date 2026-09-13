<?php

namespace App\Services\Dashboard;

use App\DataTransferObjects\ProfessionalDashboardScope;
use App\Models\Document;
use App\Models\Hospitalization;
use App\Models\Invoice;
use App\Models\PetVetAccess;
use App\Models\User;

/**
 * Pendências operacionais do dashboard do profissional (item 4 da auditoria) — o que ele
 * precisa ver em 5 segundos ao abrir a tela, com schema já pronto para todas.
 */
final class ProfessionalOperationalAlertsService
{
    /**
     * @return array{
     *     activeHospitalizations: ?int,
     *     pendingVetAccessRequests: ?int,
     *     overdueInvoices: int,
     *     upcomingInvoices: int,
     *     documentVerificationStatus: ?string,
     * }
     */
    public function collect(User $user, ProfessionalDashboardScope $scope): array
    {
        $invoiceCounters = $this->fetchOverdueAndUpcomingInvoices($scope->professionalIds);

        return [
            // Internação e acesso a prontuário são atos/dados clínicos — não se aplicam a
            // petshop puro (mesma regra de `ProfessionalCatalogCountsService::collect`).
            'activeHospitalizations' => $scope->isClinical ? $this->countActiveHospitalizations($scope->professionalIds) : null,
            'pendingVetAccessRequests' => $scope->isClinical ? $this->countPendingVetAccessRequests($scope->professionalIds) : null,
            'overdueInvoices' => $invoiceCounters['overdue'],
            'upcomingInvoices' => $invoiceCounters['upcoming'],
            'documentVerificationStatus' => $this->documentVerificationStatus($user, $scope->isClinical),
        ];
    }

    /**
     * @param  list<int>  $professionalIds
     */
    private function countActiveHospitalizations(array $professionalIds): int
    {
        return Hospitalization::whereIn('professional_id', $professionalIds)
            ->where('status', 'active')
            ->whereNull('discharge_date')
            ->count();
    }

    /**
     * Só as solicitações do PRÓPRIO profissional (`veterinarian_id`) — nunca dado do pet ainda
     * não autorizado. `PetVetAccess::scopePending()` já restringe a `status = pending`, que
     * não concede acesso nenhum (`scopeActive()` é o que concede).
     *
     * @param  list<int>  $professionalIds
     */
    private function countPendingVetAccessRequests(array $professionalIds): int
    {
        return PetVetAccess::whereIn('veterinarian_id', $professionalIds)
            ->pending()
            ->count();
    }

    /**
     * `invoices.status` tem o valor `overdue` no enum do banco, mas nenhum código de escrita
     * jamais o grava (achado de carona — grep confirma zero call-site) — sem um job que
     * transicione `pending` → `overdue` na data de vencimento, ele é um valor morto. A
     * distinção honesta é calculada aqui a partir de `due_date`, não lida do `status`.
     *
     * @param  list<int>  $professionalIds
     * @return array{overdue: int, upcoming: int}
     */
    private function fetchOverdueAndUpcomingInvoices(array $professionalIds): array
    {
        $today = now()->toDateString();

        $row = Invoice::whereIn('professional_id', $professionalIds)
            ->where('status', 'pending')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE due_date < ?) AS overdue,
                 COUNT(*) FILTER (WHERE due_date >= ?) AS upcoming',
                [$today, $today]
            )
            ->first();

        return [
            'overdue' => (int) $row->overdue,
            'upcoming' => (int) $row->upcoming,
        ];
    }

    /**
     * Status do documento que sustenta o selo "verificado" deste perfil: CRMV para quem
     * pratica ato clínico, CNPJ para petshop puro. Sempre o documento do usuário logado, nunca
     * agregado pela equipe — verificação é por pessoa, não por organização.
     *
     * `null` quando nenhum documento desse tipo foi enviado ainda — diferente de `'pending'`
     * (enviado, aguardando análise) e de `'rejected'` (analisado e recusado, hoje só visível
     * olhando o documento na aba de documentos, nunca no dashboard).
     */
    private function documentVerificationStatus(User $user, bool $isClinical): ?string
    {
        $documentType = $isClinical ? 'crmv' : 'cnpj';

        return Document::where('user_id', $user->id)
            ->where('document_type', $documentType)
            ->latest()
            ->value('verification_status');
    }
}
