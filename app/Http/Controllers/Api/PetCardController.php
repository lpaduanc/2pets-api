<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pet;
use App\Services\PetCard\PetCardService;
use App\Services\PetCard\QRCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PetCardController extends Controller
{
    public function __construct(
        private readonly PetCardService $petCardService,
        private readonly QRCodeService $qrCodeService
    ) {}

    public function getQRCode(Request $request, int $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $qrCodeUrl = $this->qrCodeService->generateQRCodeUrl(
            $this->qrCodeService->generatePetCardUrl($pet->public_id)
        );

        return response()->json([
            'qr_code_url' => $qrCodeUrl,
            'card_url' => $this->qrCodeService->generatePetCardUrl($pet->public_id),
        ]);
    }

    /**
     * Abre o alerta de pet perdido.
     *
     * Nada e obrigatorio de proposito. O app dispara este endpoint a partir de
     * um dialogo de confirmacao com um unico botao (`PetCardPage.vue`,
     * `markAsLost()`) e o tutor pode ter negado a permissao de localizacao no
     * aparelho; exigir `message` (como era ate aqui) so produzia 422 no unico
     * chamador real, e o app engolia o erro mostrando "alerta enviado" assim
     * mesmo. Sem corpo, o alerta usa uma descricao padrao e o endereco de
     * cadastro do tutor como centro do raio.
     */
    public function markLost(Request $request, int $petId): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'nullable|string|max:500',
            'last_seen_location' => 'nullable|string|max:255',
            // As duas coordenadas andam juntas: metade de um ponto nao localiza nada.
            'last_seen_latitude' => 'nullable|numeric|between:-90,90|required_with:last_seen_longitude',
            'last_seen_longitude' => 'nullable|numeric|between:-180,180|required_with:last_seen_latitude',
            'alert_radius_km' => 'nullable|numeric|min:1|max:'.config('lost-pet.max_radius_km'),
        ]);

        $pet = Pet::where('id', $petId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $alert = $this->petCardService->markAsLost($pet, array_filter(
            $validated,
            static fn ($value) => $value !== null
        ));

        return response()->json([
            'message' => 'Pet marked as lost',
            'alert_id' => $alert->id,
            'alert_radius_km' => (float) $alert->alert_radius_km,
            // `false` diz ao app que ninguem sera notificado: o tutor nao tem
            // endereco cadastrado e nao mandou coordenada.
            'notifying_nearby' => $alert->last_seen_latitude !== null && $alert->last_seen_longitude !== null,
        ]);
    }

    public function markFound(Request $request, int $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->petCardService->markAsFound($pet);

        return response()->json(['message' => 'Pet marked as found']);
    }
}
