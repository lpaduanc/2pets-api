<?php

namespace App\Http\Controllers\Api;

use App\Enums\MedicalRecordStatus;
use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\MedicalRecord\StoreMedicalRecordRequest;
use App\Http\Requests\MedicalRecord\UpdateMedicalRecordRequest;
use App\Models\MedicalRecord;
use App\Services\Medical\PrescriptionLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Mantém o formato de resposta legado (model cru) — a entrega nova de `MedicalRecordResource`
 * fica em `ConsultationController` e nas rotas compartilhadas de leitura
 * (`GET pets/{pet}/medical-records`, `GET medical-records/{id}`). Trocar o formato aqui
 * quebraria `MedicalRecordSymptomsEncodingTest`, que já depende do shape `{message, record}`
 * — não era o escopo desta entrega reescrever um contrato que já tem consumidor.
 */
class MedicalRecordController extends Controller
{
    use AuthorizesPetAccess, PaginatesResults;

    /**
     * The records screen sends its own `per_page`; this default only has to keep
     * the callers that read the whole list to populate a select working.
     */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly PrescriptionLifecycleService $prescriptionLifecycleService,
    ) {}

    public function index(Request $request)
    {
        // Vet sees only records they authored (draft and finalized). Tutor's view is
        // served by `pets/{pet}/medical-records` (finalized only).
        $records = MedicalRecord::with(['pet', 'professional', 'appointment'])
            ->where('professional_id', $request->user()->id)
            ->orderBy('record_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        // Invariante de privacidade docs/atendimento-veterinario/
        // 08-consulta-autorizada-por-agendamento.md §B: um dos próprios rascunhos/finalizados
        // pode ser de um pet visto só por agendamento (sem PetVetAccess) — o `pet` embutido
        // no dump legado precisa vir minimizado nesse caso.
        $this->minimizeUngrantedPetsOn($request->user(), $records->getCollection());

        return JsonResource::collection($records);
    }

    public function store(StoreMedicalRecordRequest $request)
    {
        $data = $request->validated();

        // Defensive check: a vet (or anyone) must have write access to the pet they
        // are creating a record for. Without this gate the pet_id in the payload is
        // completely unchecked — someone could attach a record to any pet.
        $this->resolvePetForWrite($request, (int) $data['pet_id']);

        $data['professional_id'] = $request->user()->id;
        $data['status'] = MedicalRecordStatus::DRAFT->value;
        $record = MedicalRecord::create($data);

        return response()->json([
            'message' => 'Prontuário criado com sucesso!',
            'record' => $record,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $record = MedicalRecord::with(['pet', 'professional', 'appointment'])->findOrFail($id);

        // MedicalRecordPolicy handles the triangle owner/author/granted-vet, and hides drafts.
        Gate::forUser($request->user())->authorize('view', $record);

        // Invariante de privacidade §B: autor sem PetVetAccess (agendamento apenas) só vê a
        // identidade mínima do pet embutida aqui, nunca o cadastro clínico completo.
        $record->setRelation('pet', $this->minimizePetUnlessFullAccess($request->user(), $record->pet));

        return response()->json($record);
    }

    public function update(UpdateMedicalRecordRequest $request, $id)
    {
        $record = MedicalRecord::findOrFail($id);

        // Author-only, and only while draft (MedicalRecordPolicy::update) — once
        // finalized, correction is a MedicalRecordAddendum, never a rewrite here.
        Gate::forUser($request->user())->authorize('update', $record);

        $record->update($request->validated());

        return response()->json([
            'message' => 'Prontuário atualizado com sucesso!',
            'record' => $record,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $record = MedicalRecord::findOrFail($id);

        // Author-only, and only while draft (bug fix — contrato §7): finalizado nunca se apaga.
        Gate::forUser($request->user())->authorize('delete', $record);

        // Contrato docs/atendimento-veterinario/03-contrato-receituario.md §1: descartar um
        // rascunho leva junto toda `Prescription` daquele atendimento ainda não emitida — uma
        // receita de um atendimento que nunca existiu não tem validade para continuar visível.
        $this->prescriptionLifecycleService->discardDraftPrescriptions($record);

        $record->delete();

        return response()->json(['message' => 'Prontuário removido com sucesso!']);
    }
}
