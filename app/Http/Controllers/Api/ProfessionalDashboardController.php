<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalDashboardStatsResource;
use App\Services\Dashboard\ProfessionalDashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfessionalDashboardController extends Controller
{
    public function __construct(private readonly ProfessionalDashboardStatsService $statsService) {}

    /**
     * Get dashboard statistics for professional.
     *
     * `appointments`/`invoices`/`services`/`inventories`/`reviews`.`professional_id` are FKs to
     * `users.id` (see `AppointmentController::store`/`InvoiceController::store`, which set it
     * from `$request->user()->id`) — NOT to `professionals.id`. The actual aggregation scope
     * (personal vs. clinic-wide) is decided by `ProfessionalScopeResolver`.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $professional = $user->professional;

        if (! $professional) {
            return response()->json(['error' => 'Professional profile not found'], 404);
        }

        $payload = $this->statsService->build($user, $professional);

        return (new ProfessionalDashboardStatsResource($payload))->response();
    }
}
