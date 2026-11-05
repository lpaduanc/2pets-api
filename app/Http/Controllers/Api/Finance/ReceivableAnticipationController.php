<?php

namespace App\Http\Controllers\Api\Finance;

use App\Enums\AnticipationMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SimulateAnticipationRequest;
use App\Http\Requests\Finance\StoreAnticipationRequest;
use App\Http\Resources\Finance\ReceivableAnticipationResource;
use App\Models\ReceivableAnticipation;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\Anticipation\AnticipationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Antecipação de recebíveis (docs/gap-petflow/PLANO.md, 2.1): simular, confirmar, listar e
 * cancelar. `finance.anticipation` pelo cargo nas organizações do escopo; escopo comercial no service.
 */
class ReceivableAnticipationController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly AnticipationService $anticipations,
        private readonly SalePermissionGate $permissions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAnticipation($request);
        $anticipations = $this->scope->scopeQuery(ReceivableAnticipation::query(), $request->user())
            ->with(['items.receivable', 'account:id,name'])
            ->orderByDesc('anticipated_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return ReceivableAnticipationResource::collection($anticipations);
    }

    public function simulate(SimulateAnticipationRequest $request): JsonResponse
    {
        $this->authorizeAnticipation($request);

        return response()->json(['data' => $this->anticipations->simulate(
            $request->user(),
            array_map('intval', $request->input('receivable_ids')),
            $request->string('anticipated_at')->toString(),
            AnticipationMode::from($request->string('mode')->toString()),
            (string) $request->input('rate'),
        )]);
    }

    public function store(StoreAnticipationRequest $request): JsonResponse
    {
        $this->authorizeAnticipation($request);
        $anticipation = $this->anticipations->confirm($request->user(), [
            'receivable_ids' => array_map('intval', $request->input('receivable_ids')),
            'anticipated_at' => $request->string('anticipated_at')->toString(),
            'mode' => $request->string('mode')->toString(),
            'rate' => (string) $request->input('rate'),
            'account_id' => $request->integer('account_id'),
        ]);

        return (new ReceivableAnticipationResource($anticipation->load('account:id,name')))
            ->additional(['message' => 'Antecipação confirmada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(Request $request, int $id): ReceivableAnticipationResource
    {
        $this->authorizeAnticipation($request);
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $anticipation = $this->scope->scopeQuery(ReceivableAnticipation::query(), $request->user())->findOrFail($id);

        return new ReceivableAnticipationResource($this->anticipations->cancel($request->user(), $anticipation, $request->input('reason')));
    }

    private function authorizeAnticipation(Request $request): void
    {
        abort_unless($this->permissions->allowsAcrossScope($request->user(), SalePermissionGate::ANTICIPATION), 403, 'Você não tem permissão para antecipar recebíveis.');
    }
}
