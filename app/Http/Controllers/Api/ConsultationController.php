<?php

namespace App\Http\Controllers\Api;

use App\Enums\MedicalRecordStatus;
use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\StoreWalkInAppointmentRequest;
use App\Http\Requests\MedicalRecord\FinalizeMedicalRecordRequest;
use App\Http\Requests\MedicalRecord\StoreMedicalRecordAddendumRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ExamResource;
use App\Http\Resources\MedicalRecordAddendumResource;
use App\Http\Resources\MedicalRecordResource;
use App\Models\Appointment;
use App\Models\Exam;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\Medical\ConsultationService;
use App\Services\Medical\MedicalRecordFinalizationService;
use App\Services\Medical\MedicalRecordSummaryPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Fluxo de atendimento — contrato docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §1.
 *
 * Controller fino de propósito: toda regra de negócio (transição de status, idempotência do
 * rascunho/exame, validação de finalização) vive nos Services injetados abaixo. Anexos de
 * prontuário moram em `MedicalRecordAttachmentController` (I/O de arquivo, não estado do atendimento).
 */
class ConsultationController extends Controller
{
    use AuthorizesPetAccess, PaginatesResults;

    private const DEFAULT_PENDING_PER_PAGE = 50;

    public function __construct(
        private readonly ConsultationService $consultationService,
        private readonly MedicalRecordFinalizationService $finalizationService,
        private readonly MedicalRecordSummaryPreviewService $summaryPreviewService,
    ) {}

    /**
     * POST professional/appointments/{id}/start
     *
     * Contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §A: a
     * consulta supera a questão de acesso — um agendamento confirmado (ou já em andamento,
     * idempotência) com este profissional AUTORIZA iniciar o atendimento, sem exigir
     * `PetVetAccess`. `PetVetAccess` continua sendo o portão do CADASTRO/HISTÓRICO do pet
     * (`PetController`, `MedicalRecordReadController`), não da consulta em si.
     *
     * `where('professional_id', ...)` garante que o agendamento é deste profissional; o guard
     * de status vive em `ConsultationService::start()` (regra de negócio, não HTTP).
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);

        $result = $this->consultationService->start($appointment, $request->user());

        return $this->consultationResponse($result->appointment, $result->medicalRecord, $result->exam, $request->user());
    }

    /**
     * POST professional/appointments/walk-in
     *
     * Diferente de `start()`: o walk-in cria o PRÓPRIO agendamento, então não pode se
     * autoautorizar por ele. O portão vira "o tutor já é cliente deste profissional OU o
     * profissional já tem PetVetAccess" — ver `ConsultationService::assertWalkInAuthorized`.
     */
    public function walkIn(StoreWalkInAppointmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->consultationService->startWalkIn($data, $request->user());

        return $this->consultationResponse($result->appointment, $result->medicalRecord, $result->exam, $request->user());
    }

    /** POST professional/appointments/{id}/close-without-finalizing */
    public function closeWithoutFinalizing(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);

        $this->consultationService->closeWithoutFinalizing($appointment);

        $appointment->load(['client', 'pet', 'invoice:id,appointment_id']);
        $appointment->setRelation('pet', $this->minimizePetUnlessFullAccess($request->user(), $appointment->pet));

        return (new AppointmentResource($appointment))
            ->additional(['message' => 'Consulta concluída. O prontuário continua pendente de finalização.'])
            ->response();
    }

    /**
     * GET professional/medical-records/pending
     *
     * Invariante de privacidade §B: os rascunhos são sempre do PRÓPRIO autor (`professional_id`
     * = usuário autenticado), mas o pet de um deles pode ter chegado só por agendamento — sem
     * `PetVetAccess` o cadastro completo do pet não pode vir embutido, só a identidade mínima.
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        $records = MedicalRecord::with(MedicalRecord::RESOURCE_RELATIONS)
            ->where('professional_id', $request->user()->id)
            ->where('status', MedicalRecordStatus::DRAFT->value)
            ->orderBy('record_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PENDING_PER_PAGE));

        $this->minimizeUngrantedPetsOn($request->user(), $records->getCollection());

        return MedicalRecordResource::collection($records);
    }

    /** POST professional/medical-records/{id}/finalize */
    public function finalize(FinalizeMedicalRecordRequest $request, int $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $record);

        $finalized = $this->finalizationService->finalize(
            $record,
            $request->user(),
            $request->validated('follow_up')
        );

        $finalized->load(MedicalRecord::RESOURCE_RELATIONS);
        $finalized->setRelation('pet', $this->minimizePetUnlessFullAccess($request->user(), $finalized->pet));

        return (new MedicalRecordResource($finalized))
            ->additional(['message' => 'Prontuário finalizado com sucesso!'])
            ->response();
    }

    /** POST professional/medical-records/{id}/addenda */
    public function storeAddendum(StoreMedicalRecordAddendumRequest $request, int $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Gate::forUser($request->user())->authorize('addAddendum', $record);

        $addendum = $this->finalizationService->addAddendum(
            $record,
            $request->user(),
            $request->validated('body')
        );

        return (new MedicalRecordAddendumResource($addendum->load('author')))
            ->additional(['message' => 'Adendo registrado com sucesso!'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST professional/medical-records/{id}/summary-preview
     *
     * Contrato §6: monta `summary_for_tutor` por template determinístico a partir dos campos
     * já estruturados e DEVOLVE — nunca persiste. Mesmo gate de `update` do PUT de rascunho
     * (autor + `draft`): não faz sentido pré-visualizar resumo de um prontuário que o vet não
     * pode mais editar.
     */
    public function summaryPreview(Request $request, int $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $record);

        return response()->json([
            'data' => ['summary' => $this->summaryPreviewService->preview($record)],
        ]);
    }

    /**
     * `$appointment`, `$medicalRecord` e `$exam` apontam para o mesmo pet — resolve a
     * minimização uma vez e aplica a MESMA instância nos três, senão os payloads
     * divergiriam sobre o que o profissional pode ver do mesmo pet no mesmo response.
     */
    private function consultationResponse(Appointment $appointment, ?MedicalRecord $medicalRecord, ?Exam $exam, User $professional): JsonResponse
    {
        $appointment->load(['client', 'pet', 'invoice:id,appointment_id']);
        $medicalRecord?->load(MedicalRecord::RESOURCE_RELATIONS);

        $visiblePet = $this->minimizePetUnlessFullAccess($professional, $appointment->pet);
        $appointment->setRelation('pet', $visiblePet);
        $medicalRecord?->setRelation('pet', $visiblePet);

        return response()->json([
            'data' => [
                'appointment' => new AppointmentResource($appointment),
                // Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md
                // §13.4: nulo para atendimento não clínico (banho e tosa) — não há
                // prontuário nenhum a devolver.
                'medical_record' => $medicalRecord ? new MedicalRecordResource($medicalRecord) : null,
                'exam' => $exam ? new ExamResource($exam) : null, // Contrato 10 §2/§3: só laboratory/imaging, nunca junto com medical_record.
            ],
        ]);
    }
}
