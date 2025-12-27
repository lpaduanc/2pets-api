<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfessionalDashboardController extends Controller
{
    /**
     * Get dashboard statistics for professional
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $professional = $user->professional;

        if (!$professional) {
            return response()->json(['error' => 'Professional profile not found'], 404);
        }

        $today = now()->startOfDay();
        $thisMonth = now()->startOfMonth();

        // Today's appointments
        $todayAppointments = Appointment::where('professional_id', $professional->id)
            ->whereDate('appointment_date', $today)
            ->count();

        // Monthly revenue (from paid invoices)
        $monthlyRevenue = Invoice::where('professional_id', $professional->id)
            ->where('status', 'paid')
            ->whereDate('created_at', '>=', $thisMonth)
            ->sum('total');

        // Total unique clients
        $totalClients = DB::table('appointments')
            ->where('professional_id', $professional->id)
            ->distinct('client_id')
            ->count('client_id');

        // Pending invoices
        $pendingInvoices = Invoice::where('professional_id', $professional->id)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'todayAppointments' => $todayAppointments,
            'monthlyRevenue' => number_format($monthlyRevenue, 2, '.', ''),
            'totalClients' => $totalClients,
            'pendingInvoices' => $pendingInvoices
        ]);
    }
}
