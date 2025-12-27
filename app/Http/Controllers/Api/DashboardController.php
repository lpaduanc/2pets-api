<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics for tutor
     */
    public function stats(Request $request)
    {
        $user = $request->user();

        // Get total pets count
        $totalPets = $user->pets()->count();

        // Get appointments count
        $appointments = $user->appointments()->count();

        // Get health records count (medical records for user's pets)
        $healthRecords = MedicalRecord::whereHas('pet', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->count();

        // Get wallet balance
        $walletBalance = $user->wallet_balance ?? 0;

        return response()->json([
            'totalPets' => $totalPets,
            'appointments' => $appointments,
            'healthRecords' => $healthRecords,
            'walletBalance' => number_format($walletBalance, 2, '.', '')
        ]);
    }
}
