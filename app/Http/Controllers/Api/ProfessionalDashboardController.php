<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProfessionalDashboardController extends Controller
{
    /**
     * Get dashboard statistics for professional
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $professional = $user->professional;

        if (! $professional) {
            return response()->json(['error' => 'Professional profile not found'], 404);
        }

        // `appointments`/`invoices`/`reviews`.`professional_id` are FKs to `users.id`
        // (see AppointmentController::store / InvoiceController::store, which set it
        // from `$request->user()->id`) — NOT to `professionals.id`. Using the wrong id
        // here was a pre-existing bug: it could show another user's appointments and
        // revenue whenever `professionals.id` collided with an unrelated `users.id`.
        $profId = $user->id;
        $today = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();
        $lastMonthEnd = now()->startOfMonth();

        // Stats — one query per source table (appointments, invoices, reviews)
        // instead of 8 separate counts.
        $appointmentCounters = $this->fetchAppointmentCounters($profId, $today, $tomorrow, $thisMonth);
        $todayAppointmentsCount = $appointmentCounters['today_count'];
        $pendingConfirmations = $appointmentCounters['pending_confirmations'];
        $totalClients = $appointmentCounters['total_clients'];
        $newClients = $appointmentCounters['new_clients'];

        $invoiceCounters = $this->fetchInvoiceCounters($profId, $thisMonth, $lastMonth, $lastMonthEnd);
        $monthlyRevenue = $invoiceCounters['monthly_revenue'];
        $lastMonthRevenue = $invoiceCounters['last_month_revenue'];
        $pendingInvoices = $invoiceCounters['pending_invoices'];

        $revenueTrend = $lastMonthRevenue > 0
            ? (int) round((($monthlyRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100)
            : 0;

        $rating = (float) ($professional->average_rating ?? 0);
        $totalReviews = $this->countVisibleReviews($profId);

        // Today's appointments detail
        $todayAppointments = Appointment::where('professional_id', $profId)
            ->where('appointment_date', '>=', $today)
            ->where('appointment_date', '<', $tomorrow)
            ->where('status', '!=', 'cancelled')
            ->with(['client:id,name', 'pet:id,name'])
            ->orderBy('appointment_time')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'time' => $a->appointment_time ? substr($a->appointment_time, 0, 5) : '',
                'duration' => $a->duration ?? 30,
                'clientName' => $a->client?->name ?? 'Cliente',
                'petName' => $a->pet?->name ?? '',
                'service' => $a->service_name ?? $a->service ?? 'Consulta',
                'status' => $a->status,
            ]);

        // Recent activity
        $recentActivity = [];

        $recentAppts = Appointment::where('professional_id', $profId)
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->with(['client:id,name', 'pet:id,name'])
            ->get();

        foreach ($recentAppts as $a) {
            $recentActivity[] = [
                'id' => $a->id,
                'type' => 'appointment',
                'text' => ($a->service_name ?? 'Consulta').' - '.($a->pet?->name ?? '').' ('.($a->client?->name ?? '').')',
                'time' => $a->updated_at->diffForHumans(),
            ];
        }

        $recentPayments = Invoice::where('professional_id', $profId)
            ->where('status', 'paid')
            ->orderBy('updated_at', 'desc')
            ->limit(2)
            ->get();

        foreach ($recentPayments as $inv) {
            $recentActivity[] = [
                'id' => $inv->id,
                'type' => 'payment',
                'text' => 'Pagamento recebido - R$ '.number_format($inv->total, 2, ',', '.'),
                'time' => $inv->updated_at->diffForHumans(),
            ];
        }

        return response()->json([
            'data' => [
                'stats' => [
                    'todayAppointments' => $todayAppointmentsCount,
                    'pendingConfirmations' => $pendingConfirmations,
                    'monthlyRevenue' => $monthlyRevenue,
                    'newClients' => $newClients,
                    'rating' => $rating,
                    'totalReviews' => $totalReviews,
                    'revenueTrend' => $revenueTrend,
                    'totalClients' => $totalClients,
                    'pendingInvoices' => $pendingInvoices,
                ],
                'todayAppointments' => $todayAppointments,
                'recentActivity' => $recentActivity,
            ],
        ]);
    }

    /**
     * @return array{today_count: int, pending_confirmations: int, total_clients: int, new_clients: int}
     */
    private function fetchAppointmentCounters(int $profId, Carbon $today, Carbon $tomorrow, Carbon $thisMonth): array
    {
        $row = Appointment::where('professional_id', $profId)
            ->selectRaw(
                "COUNT(*) FILTER (WHERE appointment_date >= ? AND appointment_date < ? AND status != 'cancelled') AS today_count,
                 COUNT(*) FILTER (WHERE status = 'pending') AS pending_confirmations,
                 COUNT(DISTINCT client_id) AS total_clients,
                 COUNT(DISTINCT client_id) FILTER (WHERE created_at >= ?) AS new_clients",
                [$today, $tomorrow, $thisMonth]
            )
            ->first();

        return [
            'today_count' => (int) $row->today_count,
            'pending_confirmations' => (int) $row->pending_confirmations,
            'total_clients' => (int) $row->total_clients,
            'new_clients' => (int) $row->new_clients,
        ];
    }

    /**
     * @return array{monthly_revenue: float, last_month_revenue: float, pending_invoices: int}
     */
    private function fetchInvoiceCounters(int $profId, Carbon $thisMonth, Carbon $lastMonth, Carbon $lastMonthEnd): array
    {
        $row = Invoice::where('professional_id', $profId)
            ->selectRaw(
                "COALESCE(SUM(total) FILTER (WHERE status = 'paid' AND created_at >= ?), 0) AS monthly_revenue,
                 COALESCE(SUM(total) FILTER (WHERE status = 'paid' AND created_at >= ? AND created_at < ?), 0) AS last_month_revenue,
                 COUNT(*) FILTER (WHERE status = 'pending') AS pending_invoices",
                [$thisMonth, $lastMonth, $lastMonthEnd]
            )
            ->first();

        return [
            'monthly_revenue' => (float) $row->monthly_revenue,
            'last_month_revenue' => (float) $row->last_month_revenue,
            'pending_invoices' => (int) $row->pending_invoices,
        ];
    }

    /**
     * Isolated because `is_visible` was added later and some environments may
     * still lack it — keep the defensive try/catch.
     */
    private function countVisibleReviews(int $profId): int
    {
        try {
            return Review::where('professional_id', $profId)
                ->where('is_visible', true)
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
