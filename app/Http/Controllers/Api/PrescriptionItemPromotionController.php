<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Services\Medical\PrescriptionMedicationPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /prescriptions/{prescriptionId}/items/{itemId}/promote-to-medication` — doc de domínio
 * docs/atendimento-veterinario/02-receituario-dominio.md §5.2. Ação do TUTOR, nunca do
 * profissional: por isso fica fora do prefixo `professional/`, ao contrário do resto do
 * controller de prescrições.
 */
final class PrescriptionItemPromotionController extends Controller
{
    public function __construct(
        private readonly PrescriptionMedicationPromotionService $promotionService,
    ) {}

    public function __invoke(Request $request, int $prescriptionId, int $itemId): JsonResponse
    {
        $item = $this->itemOwnedByTutor($request, $prescriptionId, $itemId);
        $prescription = $item->prescription;

        $this->assertPromotable($prescription, $item);

        $medication = $this->promotionService->promote($item, $prescription);

        return response()->json([
            'data' => $medication->fresh(),
            'message' => 'Medicação adicionada à lista de uso contínuo.',
        ], 201);
    }

    private function itemOwnedByTutor(Request $request, int $prescriptionId, int $itemId): PrescriptionItem
    {
        $item = PrescriptionItem::with('prescription.pet')
            ->where('prescription_id', $prescriptionId)
            ->findOrFail($itemId);

        $pet = $item->prescription->pet;

        if ($pet === null || $pet->user_id !== $request->user()->id) {
            abort(403, 'Somente o tutor do pet pode adicionar esta medicação à lista de uso contínuo.');
        }

        return $item;
    }

    private function assertPromotable(Prescription $prescription, PrescriptionItem $item): void
    {
        // Prescrição não emitida nunca aparece para o tutor (contrato §6) — mesma regra do
        // rascunho de prontuário.
        abort_if(! $prescription->isIssued(), 404);

        abort_if(! $item->is_continuous_use, 422, 'Este item não foi marcado como uso contínuo pelo profissional.');
    }
}
