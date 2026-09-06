<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\OpenAIService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiBusinessInsightsController extends Controller
{
    protected $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    /**
     * Generate AI-powered business insights
     */
    public function generateInsights(Request $request)
    {
        $user = $request->user();
        $professional = $user->professional;

        if (! $professional) {
            return response()->json(['error' => 'Professional profile not found'], 404);
        }

        // Collect business data. `invoices`/`appointments`/`inventories`/`services`.
        // `professional_id` are FKs to `users.id`, not `professionals.id` — passing
        // `$professional->id` here was a pre-existing bug that could leak another
        // user's data whenever the two id sequences collided.
        $businessData = $this->collectBusinessData($user->id);

        // Generate AI insights
        $insights = $this->openAIService->generateBusinessInsights($businessData);

        return response()->json([
            'insights' => $insights,
            'metrics' => $businessData,
            'generatedAt' => now()->toISOString(),
        ]);
    }

    /**
     * Collect comprehensive business data.
     *
     * One query per source table (invoices, appointments, inventory, services)
     * instead of the 13 that used to run in series.
     */
    private function collectBusinessData($professionalId)
    {
        $now = now();
        $last3Months = $now->copy()->subMonths(3);

        $revenue = $this->fetchRevenueMetrics($professionalId, $now);
        $appointments = $this->fetchAppointmentMetrics($professionalId, $last3Months);
        $clients = $this->fetchClientMetrics($professionalId);
        $inventory = $this->fetchInventoryMetrics($professionalId, $now);
        $services = $this->fetchServiceMetrics($professionalId);

        return [
            'revenue' => $revenue,
            'appointments' => $appointments,
            'clients' => $clients,
            'inventory' => $inventory,
            'services' => $services,
        ];
    }

    /**
     * `whereMonth('created_at', $now->month)` compared only the month number and
     * ignored the year, so "current month" silently included every past (or
     * future) year's same month too. Calendar-month ranges fix both the
     * correctness bug and the sargability (function-on-column) problem.
     */
    private function fetchRevenueMetrics(int $professionalId, Carbon $now): array
    {
        $currentMonthStart = $now->copy()->startOfMonth();
        $currentMonthEnd = $currentMonthStart->copy()->addMonthNoOverflow();
        $lastMonthStart = $currentMonthStart->copy()->subMonthNoOverflow();

        $row = Invoice::where('professional_id', $professionalId)
            ->selectRaw(
                "COALESCE(SUM(total) FILTER (WHERE status = 'paid' AND created_at >= ? AND created_at < ?), 0) AS current_month,
                 COALESCE(SUM(total) FILTER (WHERE status = 'paid' AND created_at >= ? AND created_at < ?), 0) AS last_month,
                 COALESCE(SUM(total) FILTER (WHERE status = 'pending'), 0) AS pending",
                [$currentMonthStart, $currentMonthEnd, $lastMonthStart, $currentMonthStart]
            )
            ->first();

        $currentMonthRevenue = (float) $row->current_month;
        $lastMonthRevenue = (float) $row->last_month;

        return [
            'currentMonth' => $currentMonthRevenue,
            'lastMonth' => $lastMonthRevenue,
            'pending' => (float) $row->pending,
            'trend' => $lastMonthRevenue > 0
                ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
                : 0,
        ];
    }

    private function fetchAppointmentMetrics(int $professionalId, Carbon $last3Months): array
    {
        $byType = Appointment::where('professional_id', $professionalId)
            ->where('appointment_date', '>=', $last3Months)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->get();

        $total = (int) $byType->sum('count');

        return [
            'total3Months' => $total,
            'byType' => $byType,
            'avgPerMonth' => $total / 3,
        ];
    }

    /**
     * Total distinct clients and repeat-client count in a single query: an
     * outer aggregate over a per-client visit-count subquery.
     */
    private function fetchClientMetrics(int $professionalId): array
    {
        $visitsPerClient = Appointment::where('professional_id', $professionalId)
            ->select('client_id')
            ->selectRaw('COUNT(*) AS visit_count')
            ->groupBy('client_id');

        $row = DB::query()
            ->fromSub($visitsPerClient, 'visits_per_client')
            ->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE visit_count > 1) AS repeat_count')
            ->first();

        $total = (int) $row->total;
        $repeat = (int) $row->repeat_count;

        return [
            'total' => $total,
            'repeat' => $repeat,
            'retentionRate' => $total > 0 ? ($repeat / $total) * 100 : 0,
        ];
    }

    private function fetchInventoryMetrics(int $professionalId, Carbon $now): array
    {
        $row = Inventory::where('professional_id', $professionalId)
            ->selectRaw(
                'COUNT(*) FILTER (WHERE quantity <= min_quantity) AS low_stock,
                 COUNT(*) FILTER (WHERE expiry_date > ? AND expiry_date <= ?) AS expiring_soon,
                 COALESCE(SUM(quantity * cost_price), 0) AS cost_value,
                 COALESCE(SUM(quantity * selling_price), 0) AS selling_value',
                [$now, $now->copy()->addDays(30)]
            )
            ->first();

        $costValue = (float) $row->cost_value;
        $sellingValue = (float) $row->selling_value;

        return [
            'lowStock' => (int) $row->low_stock,
            'expiringSoon' => (int) $row->expiring_soon,
            'costValue' => $costValue,
            'sellingValue' => $sellingValue,
            'potentialProfit' => $sellingValue - $costValue,
        ];
    }

    private function fetchServiceMetrics(int $professionalId): array
    {
        $row = Service::where('professional_id', $professionalId)
            ->where('active', true)
            ->selectRaw('COUNT(*) AS active_count, COALESCE(AVG(price), 0) AS avg_price')
            ->first();

        return [
            'active' => (int) $row->active_count,
            'avgPrice' => (float) $row->avg_price,
        ];
    }
}
