<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Immunization\ImmunizationAdherenceReportRequest;
use App\Services\Report\ImmunizationAdherenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/** `GET reports/immunization-adherence?from=&to=&organization_id=` — contrato spec 13. */
class ImmunizationAdherenceReportController extends Controller
{
    public function __construct(private readonly ImmunizationAdherenceService $adherenceService) {}

    public function __invoke(ImmunizationAdherenceReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        $summary = $this->adherenceService->summarize(
            Carbon::parse($data['from']),
            Carbon::parse($data['to']),
            $data['organization_id'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }
}
