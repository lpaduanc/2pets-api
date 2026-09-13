<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formato de resposta de `GET /professional/dashboard/stats`.
 *
 * @mixin array{stats: array, todayAppointments: \Illuminate\Support\Collection, recentActivity: array, professionalContext: array}
 */
class ProfessionalDashboardStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'stats' => $this->resource['stats'],
            'todayAppointments' => $this->resource['todayAppointments'],
            'recentActivity' => $this->resource['recentActivity'],
            'professionalContext' => $this->resource['professionalContext'],
        ];
    }
}
