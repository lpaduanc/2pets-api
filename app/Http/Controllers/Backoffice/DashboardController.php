<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Números de abertura do 2pets Admin — todos baratos (um `COUNT` agrupado por tabela). */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byRole = User::query()
            ->whereIn('role', ['tutor', 'professional', 'company'])
            ->select('role', DB::raw('COUNT(*) AS total'))
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS last_7_days', [now()->subDays(7)])
            ->selectRaw('SUM(CASE WHEN is_suspended THEN 1 ELSE 0 END) AS suspended')
            ->selectRaw('SUM(CASE WHEN email_verified_at IS NULL THEN 1 ELSE 0 END) AS unverified')
            ->selectRaw("SUM(CASE WHEN profile_completed = false AND role <> 'tutor' THEN 1 ELSE 0 END) AS incomplete")
            ->groupBy('role')
            ->get()
            ->keyBy('role');

        $sum = fn (string $column, array $roles) => (int) collect($roles)->sum(fn ($r) => $byRole[$r]->{$column} ?? 0);

        $recent = User::query()
            ->whereIn('role', ['tutor', 'professional', 'company'])
            ->latest('id')
            ->limit(8)
            ->get(['id', 'name', 'email', 'role', 'user_type', 'created_at']);

        return response()->json([
            'tutors' => [
                'total' => $sum('total', ['tutor']),
                'last_7_days' => $sum('last_7_days', ['tutor']),
                'unverified' => $sum('unverified', ['tutor']),
            ],
            'professionals' => [
                'total' => $sum('total', ['professional', 'company']),
                'last_7_days' => $sum('last_7_days', ['professional', 'company']),
                'unverified' => $sum('unverified', ['professional', 'company']),
                'incomplete' => $sum('incomplete', ['professional', 'company']),
            ],
            'suspended' => $sum('suspended', ['tutor', 'professional', 'company']),
            'pets' => Pet::count(),
            'recent_users' => $recent->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'app' => $u->role === 'tutor' ? 'tutor' : 'pro',
                'created_at' => $u->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
