<?php

namespace App\Http\Controllers\Api;

use App\Enums\PrescriptionSort;
use App\Enums\PrescriptionStatusFilter;
use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Prescription\CancelPrescriptionRequest;
use App\Http\Requests\Prescription\StorePrescriptionRequest;
use App\Http\Requests\Prescription\UpdatePrescriptionRequest;
use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use App\Services\Medical\PrescriptionDuplicationService;
use App\Services\Medical\PrescriptionLifecycleService;
use App\Services\Medical\PrescriptionSearchFilter;
use App\Services\Medical\PrescriptionSignatureService;
use App\Services\Medical\PrescriptionWriteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrescriptionController extends Controller
{
    use AuthorizesPetAccess, PaginatesResults;

    /** Covers the prescription history screen and the selects fed from it. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly PrescriptionSearchFilter $searchFilter,
        private readonly PrescriptionWriteService $writeService,
        private readonly PrescriptionLifecycleService $lifecycleService,
        private readonly PrescriptionSignatureService $signatureService,
        private readonly PrescriptionDuplicationService $duplicationService,
    ) {}

    /**
     * Filtros opcionais: `pet_id`, `status` (all|valid|expired), `search` e `sort`
     * (recent|oldest|validity). Nenhum é obrigatório — sem parâmetro, a resposta é a mesma
     * lista de sempre, ordenada por data de emissão decrescente.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Prescription::with(Prescription::RESOURCE_RELATIONS)
            ->where('professional_id', $request->user()->id);

        $this->applyPetScope($request, $query);

        PrescriptionStatusFilter::fromRequestValue($request->query('status'))->applyTo($query);

        $this->searchFilter->apply($query, $request->query('search'));

        PrescriptionSort::fromRequestValue($request->query('sort'))->applyTo($query);

        $prescriptions = $query->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return PrescriptionResource::collection($prescriptions);
    }

    public function store(StorePrescriptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Vet must have write access to the pet they're prescribing for.
        $this->resolvePetForWrite($request, (int) $data['pet_id']);

        $data['professional_id'] = $request->user()->id;

        $prescription = $this->writeService->create($data);

        return $this->respondWithPrescription($prescription, 'Prescrição criada com sucesso!', 201);
    }

    public function show(Request $request, int $id): PrescriptionResource
    {
        $prescription = $this->ownedPrescription($request, $id);

        return new PrescriptionResource($prescription->load(Prescription::RESOURCE_RELATIONS));
    }

    /** Recusa (422) quando a prescrição já foi emitida — corrigir é cancelar e reemitir. */
    public function update(UpdatePrescriptionRequest $request, int $id): JsonResponse
    {
        $prescription = $this->ownedPrescription($request, $id);
        $this->writeService->update($prescription, $request->validated());

        return $this->respondWithPrescription($prescription, 'Prescrição atualizada com sucesso!');
    }

    /** Recusa (422) quando a prescrição já foi emitida — só rascunho pode ser apagado. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->writeService->delete($this->ownedPrescription($request, $id));

        return response()->json(['message' => 'Prescrição removida com sucesso!']);
    }

    /** `POST prescriptions/{id}/issue` — só para prescrição standalone (sem `medical_record_id`). */
    public function issue(Request $request, int $id): JsonResponse
    {
        $prescription = $this->lifecycleService->issue($this->ownedPrescription($request, $id));

        return $this->respondWithPrescription($prescription, 'Prescrição emitida com sucesso!');
    }

    /** `POST prescriptions/{id}/cancel` — só para prescrição já emitida. */
    public function cancel(CancelPrescriptionRequest $request, int $id): JsonResponse
    {
        $prescription = $this->lifecycleService->cancel(
            $this->ownedPrescription($request, $id),
            $request->user(),
            $request->validated('reason')
        );

        return $this->respondWithPrescription($prescription, 'Prescrição cancelada com sucesso!');
    }

    /**
     * `POST prescriptions/{id}/sign` — assinatura eletrônica simples (spec 15). Só o autor
     * pode assinar (mesma restrição de `issue`/`cancel`, via `ownedPrescription`).
     */
    public function sign(Request $request, int $id): JsonResponse
    {
        $prescription = $this->signatureService->sign($this->ownedPrescription($request, $id));

        return $this->respondWithPrescription($prescription, 'Prescrição assinada com sucesso!');
    }

    /**
     * `POST prescriptions/{id}/duplicate` — clona os itens para uma nova prescrição
     * rascunho (spec 12, alternativa barata a um modelo de prescrição nomeado). A original
     * permanece intacta.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $duplicate = $this->duplicationService->duplicate($this->ownedPrescription($request, $id));

        return $this->respondWithPrescription($duplicate, 'Prescrição duplicada com sucesso!', 201);
    }

    /** Receitas ainda em vigor — sem prazo ou com validade a partir de hoje. */
    public function valid(Request $request): AnonymousResourceCollection
    {
        $query = Prescription::with(Prescription::RESOURCE_RELATIONS)
            ->where('professional_id', $request->user()->id)
            ->valid();

        $this->applyPetScope($request, $query);

        return PrescriptionResource::collection($query->orderBy('valid_until')->get());
    }

    /**
     * @param  Builder<Prescription>  $query
     */
    private function applyPetScope(Request $request, Builder $query): void
    {
        if (! $request->has('pet_id')) {
            return;
        }

        $pet = $this->resolvePetForRead($request, (int) $request->query('pet_id'));
        $query->where('pet_id', $pet->id);
    }

    private function ownedPrescription(Request $request, int $id): Prescription
    {
        return Prescription::query()
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);
    }

    private function respondWithPrescription(Prescription $prescription, string $message, int $status = 200): JsonResponse
    {
        return (new PrescriptionResource($prescription->load(Prescription::RESOURCE_RELATIONS)))
            ->additional(['message' => $message])
            ->response()
            ->setStatusCode($status);
    }
}
