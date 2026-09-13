<?php

namespace App\Services\Dashboard;

use App\DataTransferObjects\DashboardDateWindow;
use App\DataTransferObjects\ProfessionalDashboardScope;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Professional;
use App\Models\Review;
use App\Models\User;

/**
 * Orquestra `GET /professional/dashboard/stats`. Move para cá a regra de negócio que antes
 * vivia inteira em `ProfessionalDashboardController::stats()` (Object Calisthenics: controller
 * só recebe requisição, delega e devolve resource) e adiciona os contadores que a auditoria da
 * tela inicial encontrou mortos (`appointmentsTrend`, `clientsTrend`) — ver
 * `ProfessionalCatalogCountsService`/`ProfessionalOperationalAlertsService` para o resto e
 * `ProfessionalDashboardActivityService` para a agenda do dia e o feed de atividade.
 */
final class ProfessionalDashboardStatsService
{
    public function __construct(
        private readonly ProfessionalScopeResolver $scopeResolver,
        private readonly ProfessionalCatalogCountsService $catalogCounts,
        private readonly ProfessionalOperationalAlertsService $operationalAlerts,
        private readonly ProfessionalDashboardActivityService $activity,
    ) {}

    /**
     * @return array{stats: array, todayAppointments: \Illuminate\Support\Collection, recentActivity: array, professionalContext: array}
     */
    public function build(User $user, Professional $professional): array
    {
        $scope = $this->scopeResolver->resolve($user);
        $window = new DashboardDateWindow;

        $appointmentCounters = $this->fetchAppointmentCounters($scope->professionalIds, $window);
        $invoiceCounters = $this->fetchInvoiceCounters($scope->professionalIds, $window);

        $stats = array_merge(
            $this->buildStats($professional, $scope, $appointmentCounters, $invoiceCounters),
            $this->catalogCounts->collect($scope),
            $this->operationalAlerts->collect($user, $scope),
        );

        return [
            'stats' => $stats,
            'todayAppointments' => $this->activity->todayAppointments($scope->professionalIds, $window),
            'recentActivity' => $this->activity->recentActivity($scope->professionalIds),
            'professionalContext' => $this->buildProfessionalContext($professional, $scope),
        ];
    }

    /**
     * @param  list<int>  $professionalIds
     */
    private function buildStats(Professional $professional, ProfessionalDashboardScope $scope, array $appointmentCounters, array $invoiceCounters): array
    {
        return [
            'todayAppointments' => $appointmentCounters['today_count'],
            'pendingConfirmations' => $appointmentCounters['pending_confirmations'],
            'appointmentsTrend' => $this->percentTrend($appointmentCounters['today_count'], $appointmentCounters['yesterday_count']),
            'monthlyRevenue' => $invoiceCounters['monthly_revenue'],
            'revenueTrend' => $this->percentTrend($invoiceCounters['monthly_revenue'], $invoiceCounters['last_month_revenue']),
            'pendingInvoices' => $invoiceCounters['pending_invoices'],
            'newClients' => $appointmentCounters['new_clients'],
            'clientsTrend' => $this->percentTrend($appointmentCounters['new_clients'], $appointmentCounters['last_month_new_clients']),
            'totalClients' => $appointmentCounters['total_clients'],
            'rating' => (float) ($professional->average_rating ?? 0),
            'totalReviews' => $this->countVisibleReviews($scope->professionalIds),
        ];
    }

    /**
     * @param  list<int>  $professionalIds
     * @return array{today_count: int, yesterday_count: int, pending_confirmations: int, total_clients: int, new_clients: int, last_month_new_clients: int}
     */
    private function fetchAppointmentCounters(array $professionalIds, DashboardDateWindow $window): array
    {
        $row = Appointment::whereIn('professional_id', $professionalIds)->selectRaw(
            "COUNT(*) FILTER (WHERE appointment_date >= ? AND appointment_date < ? AND status != 'cancelled') AS today_count,
             COUNT(*) FILTER (WHERE appointment_date >= ? AND appointment_date < ? AND status != 'cancelled') AS yesterday_count,
             COUNT(*) FILTER (WHERE status = 'pending') AS pending_confirmations,
             COUNT(DISTINCT client_id) AS total_clients,
             COUNT(DISTINCT client_id) FILTER (WHERE created_at >= ?) AS new_clients,
             COUNT(DISTINCT client_id) FILTER (WHERE created_at >= ? AND created_at < ?) AS last_month_new_clients",
            [$window->today, $window->tomorrow, $window->yesterday, $window->today, $window->thisMonth, $window->lastMonth, $window->lastMonthEnd]
        )->first();

        return [
            'today_count' => (int) $row->today_count,
            'yesterday_count' => (int) $row->yesterday_count,
            'pending_confirmations' => (int) $row->pending_confirmations,
            'total_clients' => (int) $row->total_clients,
            'new_clients' => (int) $row->new_clients,
            'last_month_new_clients' => (int) $row->last_month_new_clients,
        ];
    }

    /**
     * @param  list<int>  $professionalIds
     * @return array{monthly_revenue: float, last_month_revenue: float, pending_invoices: int}
     */
    private function fetchInvoiceCounters(array $professionalIds, DashboardDateWindow $window): array
    {
        $row = Invoice::whereIn('professional_id', $professionalIds)->selectRaw(
            "COALESCE(SUM(total) FILTER (WHERE status = 'paid' AND created_at >= ?), 0) AS monthly_revenue,
             COALESCE(SUM(total) FILTER (WHERE status = 'paid' AND created_at >= ? AND created_at < ?), 0) AS last_month_revenue,
             COUNT(*) FILTER (WHERE status = 'pending') AS pending_invoices",
            [$window->thisMonth, $window->lastMonth, $window->lastMonthEnd]
        )->first();

        return [
            'monthly_revenue' => (float) $row->monthly_revenue,
            'last_month_revenue' => (float) $row->last_month_revenue,
            'pending_invoices' => (int) $row->pending_invoices,
        ];
    }

    /**
     * `null` quando não há base de comparação (período anterior zerado) — diferente de `0`,
     * que afirmaria "sem variação". Vale para os três (`appointmentsTrend`, `clientsTrend`,
     * `revenueTrend`): antes desta unificação, `revenueTrend` sozinho ainda respondia `0`
     * nesse caso (defeito original que motivou a auditoria do dashboard, sobrevivendo num
     * campo). Front já esconde o selo de tendência quando o valor é falsy.
     */
    private function percentTrend(float $current, float $previous): ?int
    {
        if ($previous === 0.0) {
            return null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * Isolado porque `is_visible` foi adicionado depois e algum ambiente pode ainda não ter a
     * coluna — mantém o `try/catch` defensivo que já existia antes desta mudança.
     *
     * @param  list<int>  $professionalIds
     */
    private function countVisibleReviews(array $professionalIds): int
    {
        try {
            return Review::whereIn('professional_id', $professionalIds)
                ->where('is_visible', true)
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function buildProfessionalContext(Professional $professional, ProfessionalDashboardScope $scope): array
    {
        return [
            'role' => $scope->role,
            'professionalType' => $professional->professional_type?->value,
            'isClinical' => $scope->isClinical,
            'isClinicAggregate' => $scope->isClinicAggregate,
        ];
    }
}
