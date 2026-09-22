<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LostPetAlertResource;
use App\Models\LostPetAlert;
use App\Services\LostPet\LostPetAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Menu "Pets Perdidos" — o destino permanente do alerta que hoje so existia
 * como push.
 *
 * Um push e efemero: quem estava com o celular no bolso, ou limpou a bandeja
 * de notificacoes, perdia o alerta de um pet perdido a 2 km de casa para
 * sempre. Aqui o alerta fica de pe enquanto o tutor nao encerrar.
 *
 * `nearby` usa o raio de CADA alerta, nao um raio fixo do observador
 * ({@see LostPetAlertService::getAlertsReaching()}): a lista tem que conter
 * exatamente os alertas que notificaram este usuario.
 */
class LostPetController extends Controller
{
    public function __construct(
        private readonly LostPetAlertService $lostPetAlertService
    ) {}

    /**
     * Lista unica consumida pelo menu e pelo contador do cabecalho: os dois
     * precisam do mesmo dado, e separar em duas rotas so dobraria o round trip
     * na abertura do app.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $mine = $this->lostPetAlertService->getActiveAlertsForUser($user->id);
        $nearby = $this->nearbyFor($request);

        return response()->json([
            'data' => [
                'mine' => LostPetAlertResource::collection($mine)->toArray($request),
                'nearby' => LostPetAlertResource::collection($nearby)->toArray($request),
            ],
            'meta' => [
                'mine_count' => $mine->count(),
                'nearby_count' => $nearby->count(),
                'total_count' => $mine->count() + $nearby->count(),
                // `false` = o usuario nao tem endereco cadastrado nem mandou
                // coordenada; a lista "perto de mim" vem vazia por falta de
                // referencia, nao por falta de pet perdido. O app usa isso para
                // convidar a completar o cadastro em vez de dizer "tudo certo".
                'has_reference_point' => $this->referencePoint($request) !== null,
            ],
        ]);
    }

    /**
     * Encerra o alerta. So o tutor dono do pet fecha — quem avista um pet
     * reporta um avistamento, que e outro fluxo (`FoundPetReport`) e nao tira
     * o alerta do ar.
     */
    public function markFound(Request $request, int $alertId): JsonResponse
    {
        $alert = LostPetAlert::where('id', $alertId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->firstOrFail();

        $alert->markAsFound($request->input('details') ?? 'Tutor informou que encontrou o pet.');

        return response()->json([
            'message' => 'Alerta encerrado',
            'data' => new LostPetAlertResource($alert->fresh(['pet', 'user'])),
        ]);
    }

    /**
     * Ponto de referencia, em ordem: a coordenada que o app mandou (o usuario
     * pode estar em outra cidade) e depois o endereco de cadastro.
     *
     * @return array{0: float, 1: float}|null
     */
    private function referencePoint(Request $request): ?array
    {
        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
        ]);

        $latitude = $validated['latitude'] ?? $request->user()->latitude;
        $longitude = $validated['longitude'] ?? $request->user()->longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [(float) $latitude, (float) $longitude];
    }

    private function nearbyFor(Request $request): Collection
    {
        $point = $this->referencePoint($request);

        if ($point === null) {
            return collect();
        }

        return $this->lostPetAlertService->getAlertsReaching($point[0], $point[1], $request->user()->id);
    }
}
