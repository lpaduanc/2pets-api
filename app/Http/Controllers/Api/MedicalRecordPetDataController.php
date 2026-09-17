<?php

namespace App\Http\Controllers\Api;

use App\Enums\MedicalRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MedicalRecord\ApplyPetDataRequest;
use App\Models\MedicalRecord;
use App\Services\Medical\PetDataPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /medical-records/{id}/pet-data-diff` e `POST /medical-records/{id}/apply-to-pet` —
 * contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §D. Ação do
 * TUTOR, nunca do profissional — por isso fica fora do prefixo `professional/`, mesmo espírito
 * de `PrescriptionItemPromotionController`.
 */
class MedicalRecordPetDataController extends Controller
{
    public function __construct(
        private readonly PetDataPromotionService $promotionService,
    ) {}

    /** GET /medical-records/{id}/pet-data-diff */
    public function diff(Request $request, int $id): JsonResponse
    {
        $record = $this->authorizedRecord($request, $id);

        return response()->json(['data' => $this->promotionService->diff($record)]);
    }

    /** POST /medical-records/{id}/apply-to-pet */
    public function apply(ApplyPetDataRequest $request, int $id): JsonResponse
    {
        $record = $this->authorizedRecord($request, $id);
        $this->assertFinalized($record);

        $changes = $this->promotionService->apply($record, $request->user(), $request->validated('fields'));

        return response()->json([
            'data' => ['changes' => $changes],
            'message' => $changes === []
                ? 'Nenhuma divergência para aplicar ao cadastro do pet.'
                : 'Cadastro do pet atualizado a partir da consulta.',
        ]);
    }

    /**
     * `MedicalRecordPolicy::applyToPet` vale para os dois endpoints: só o tutor dono do pet,
     * mesmo o diff (que não escreve nada) — ver a nota do contrato §D sobre não abrir leitura
     * a quem não pode aplicar.
     */
    private function authorizedRecord(Request $request, int $id): MedicalRecord
    {
        $record = MedicalRecord::with('pet')->findOrFail($id);

        Gate::forUser($request->user())->authorize('applyToPet', $record);

        return $record;
    }

    /**
     * Rascunho ainda pode mudar de ideia — promover dado não definitivo sujaria o cadastro
     * com algo que o próprio vet pode reescrever no minuto seguinte (contrato §D).
     */
    private function assertFinalized(MedicalRecord $record): void
    {
        abort_if(
            $record->status !== MedicalRecordStatus::FINALIZED,
            422,
            'Este prontuário ainda é um rascunho — só é possível atualizar o cadastro do pet a partir de uma consulta finalizada.'
        );
    }
}
