<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hospitalization\StoreHospitalizationProgressNoteRequest;
use App\Http\Resources\HospitalizationProgressNoteResource;
use App\Models\Hospitalization;
use App\Models\HospitalizationProgressNote;
use App\Services\Hospitalization\HospitalizationProgressNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1/§7.
 *
 * Controller fino: `HospitalizationPolicy::writeProgressNote` decide quem escreve (autor OU
 * colega com `medical-records.create` na mesma organização); a regra de negócio (estadia
 * ativa, escrita do histórico de peso) vive em `HospitalizationProgressNoteService`. Sem
 * rota de `update`/`destroy` de propósito — a imutabilidade é o requisito central (contrato
 * §1.3), travada também no model (`HospitalizationProgressNote::booted()`).
 */
class HospitalizationProgressNoteController extends Controller
{
    public function __construct(private readonly HospitalizationProgressNoteService $progressNoteService) {}

    public function store(StoreHospitalizationProgressNoteRequest $request, int $id): JsonResponse
    {
        $hospitalization = Hospitalization::with('professional')->findOrFail($id);
        Gate::forUser($request->user())->authorize('writeProgressNote', $hospitalization);

        $note = $this->progressNoteService->create($hospitalization, $request->user(), $request->validated());

        return (new HospitalizationProgressNoteResource($note->load(HospitalizationProgressNote::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Evolução registrada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }
}
