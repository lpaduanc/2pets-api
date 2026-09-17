<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hospitalization\StoreHospitalizationClinicalActRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Models\Hospitalization;
use App\Models\MedicalRecord;
use App\Services\Hospitalization\HospitalizationClinicalActService;
use Illuminate\Http\JsonResponse;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2.
 *
 * Controller fino de propósito, mesmo padrão de `HospitalizationController`/
 * `ExamController`: toda a regra (autorização de conta, gate de veterinário, criação
 * do prontuário + cobrança na mesma transação) vive em `HospitalizationClinicalActService`.
 */
class HospitalizationClinicalActController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(private readonly HospitalizationClinicalActService $clinicalActService) {}

    /** POST professional/hospitalizations/{id}/clinical-acts */
    public function store(StoreHospitalizationClinicalActRequest $request, int $id): JsonResponse
    {
        $hospitalization = Hospitalization::findOrFail($id);

        $record = $this->clinicalActService->openAct($hospitalization, $request->user(), $request->validated());

        $record->load(MedicalRecord::RESOURCE_RELATIONS);
        $record->setRelation('pet', $this->minimizePetUnlessFullAccess($request->user(), $record->pet));

        return (new MedicalRecordResource($record))
            ->additional(['message' => 'Ato clínico aberto na internação.'])
            ->response()
            ->setStatusCode(201);
    }
}
