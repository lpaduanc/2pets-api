<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hospitalization\StoreHospitalizationCareLogRequest;
use App\Http\Resources\HospitalizationCareLogResource;
use App\Models\Hospitalization;
use App\Models\HospitalizationCareLog;
use App\Services\Hospitalization\HospitalizationCareLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4/§7.
 *
 * Mesmo padrão de `HospitalizationProgressNoteController`: `HospitalizationPolicy::writeCareLog`
 * autoriza, `HospitalizationCareLogService` cuida da regra de negócio. "Motivo obrigatório
 * quando `not_done`" já foi validado em `StoreHospitalizationCareLogRequest` antes de chegar
 * aqui.
 */
class HospitalizationCareLogController extends Controller
{
    public function __construct(private readonly HospitalizationCareLogService $careLogService) {}

    public function store(StoreHospitalizationCareLogRequest $request, int $id): JsonResponse
    {
        $hospitalization = Hospitalization::with('professional')->findOrFail($id);
        Gate::forUser($request->user())->authorize('writeCareLog', $hospitalization);

        $careLog = $this->careLogService->create($hospitalization, $request->user(), $request->validated());

        return (new HospitalizationCareLogResource($careLog->load(HospitalizationCareLog::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Cuidado registrado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }
}
