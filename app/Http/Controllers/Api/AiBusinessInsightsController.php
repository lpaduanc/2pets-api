<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Inventory;
use App\Models\Service;
use App\Services\OpenAIService;
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

        if (!$professional) {
            return response()->json(['error' => 'Professional profile not found'], 404);
        }

        // Collect business data
        $businessData = $this->collectBusinessData($professional->id);

        // Generate AI insights
        $insights = $this->openAIService->generateBusinessInsights($businessData);

        return response()->json([
            'insights' => $insights,
            'metrics' => $businessData,
            'generatedAt' => now()->toISOString(),
        ]);
    }

    /**
     * Collect comprehensive business data
     */
    private function collectBusinessData($professionalId)
    {
        $now = now();
        $lastMonth = $now->copy()->subMonth();
        $last3Months = $now->copy()->subMonths(3);

        // Revenue Analysis
        $currentMonthRevenue = Invoice::where('professional_id', $professionalId)
            ->where('status', 'paid')
            ->whereMonth('created_at', $now->month)
            ->sum('total');

        $lastMonthRevenue = Invoice::where('professional_id', $professionalId)
            ->where('status', 'paid')
            ->whereMonth('created_at', $lastMonth->month)
            ->sum('total');

        $pendingRevenue = Invoice::where('professional_id', $professionalId)
            ->where('status', 'pending')
            ->sum('total');

        // Appointment Analysis
        $totalAppointments = Appointment::where('professional_id', $professionalId)
            ->where('appointment_date', '>=', $last3Months)
            ->count();

        $appointmentsByType = Appointment::where('professional_id', $professionalId)
            ->where('appointment_date', '>=', $last3Months)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->get();

        // Client Metrics
        $totalClients = Appointment::where('professional_id', $professionalId)
            ->distinct('client_id')
            ->count('client_id');

        $repeatClients = Appointment::where('professional_id', $professionalId)
            ->select('client_id', DB::raw('count(*) as visit_count'))
            ->groupBy('client_id')
            ->having('visit_count', '>', 1)
            ->count();

        // Inventory Analysis
        $lowStockItems = Inventory::where('professional_id', $professionalId)
            ->whereRaw('quantity <= min_quantity')
            ->count();

        $expiringItems = Inventory::where('professional_id', $professionalId)
            ->where('expiry_date', '<=', $now->copy()->addDays(30))
            ->where('expiry_date', '>', $now)
            ->count();

        $inventoryValue = Inventory::where('professional_id', $professionalId)
            ->selectRaw('SUM(quantity * cost_price) as cost_value, SUM(quantity * selling_price) as selling_value')
            ->first();

        // Service Analysis
        $activeServices = Service::where('professional_id', $professionalId)
            ->where('active', true)
            ->count();

        $avgServicePrice = Service::where('professional_id', $professionalId)
            ->where('active', true)
            ->avg('price');

        return [
            'revenue' => [
                'currentMonth' => (float) $currentMonthRevenue,
                'lastMonth' => (float) $lastMonthRevenue,
                'pending' => (float) $pendingRevenue,
                'trend' => $lastMonthRevenue > 0
                    ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
                    : 0,
            ],
            'appointments' => [
                'total3Months' => $totalAppointments,
                'byType' => $appointmentsByType,
                'avgPerMonth' => $totalAppointments / 3,
            ],
            'clients' => [
                'total' => $totalClients,
                'repeat' => $repeatClients,
                'retentionRate' => $totalClients > 0 ? ($repeatClients / $totalClients) * 100 : 0,
            ],
            'inventory' => [
                'lowStock' => $lowStockItems,
                'expiringSoon' => $expiringItems,
                'costValue' => (float) ($inventoryValue->cost_value ?? 0),
                'sellingValue' => (float) ($inventoryValue->selling_value ?? 0),
                'potentialProfit' => (float) (($inventoryValue->selling_value ?? 0) - ($inventoryValue->cost_value ?? 0)),
            ],
            'services' => [
                'active' => $activeServices,
                'avgPrice' => (float) $avgServicePrice,
            ],
        ];
    }
}
