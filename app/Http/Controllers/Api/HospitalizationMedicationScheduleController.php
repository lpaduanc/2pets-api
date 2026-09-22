<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalizationMedicationScheduleResource;
use App\Models\Hospitalization;
use App\Services\Hospitalization\HospitalizationMedicationScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * `GET hospitalizations/{id}/medication-schedule` — contrato docs/gap-simplesvet/specs/
 * 12-internacao-mapa-execucao-spec.md §3. Mesma autorização de leitura da internação
 * (`HospitalizationPolicy::view`) — sem regra nova.
 */
class HospitalizationMedicationScheduleController extends Controller
{
    public function __construct(private readonly HospitalizationMedicationScheduleService $scheduleService) {}

    public function __invoke(Request $request, int $id): AnonymousResourceCollection
    {
        $hospitalization = Hospitalization::findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $hospitalization);

        $schedule = $this->scheduleService->forHospitalization($hospitalization);

        return HospitalizationMedicationScheduleResource::collection($schedule);
    }
}
