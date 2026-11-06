<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\PayCommissionSettlementRequest;
use App\Http\Requests\Commercial\StoreCommissionSettlementRequest;
use App\Http\Resources\Commercial\CommissionSettlementResource;
use App\Models\CommissionClawback;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\CommissionSettlementService;
use App\Services\Commercial\Reversal\CommissionDeductionApplier;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Prévia, fechamento e liquidação de comissão por funcionário — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 */
class CommissionSettlementController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CommissionSettlementService $settlements,
        private readonly CommissionDeductionApplier $deductions,
    ) {}

    /**
     * O que fecharia agora se `store()` fosse chamado — mesma query, sem gravar nada.
     */
    public function open(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', CommissionSettlement::class);
        $request->validate(['staff_id' => ['required', 'integer'], 'received_until' => ['required', 'date']]);

        $items = $this->settlements->openSummary(
            $request->user(),
            $request->integer('staff_id'),
            CarbonImmutable::parse($request->string('received_until')->toString()),
        );

        return response()->json([
            'data' => $items->map(fn (array $priced) => [
                'sale_item_id' => $priced['sale_item']->id,
                'description' => $priced['sale_item']->description,
                'base_amount' => $priced['base_amount'],
                'commission_amount' => $priced['commission_amount'],
                // PLANO 2.6: por qual gatilho e em que data a comissão nasceu.
                'trigger' => $priced['trigger']->value,
                'trigger_label' => $priced['trigger']->label(),
                'born_at' => $priced['born_at']->toIso8601String(),
            ]),
            'meta' => ['total' => round($items->sum('commission_amount'), 2)],
            // Comissão estornada que o próximo fechamento deste profissional vai descontar
            // (PLANO 2.3) — visível mesmo sem fechamento novo.
            'pending_deductions' => $this->pendingDeductions($request->integer('staff_id')),
        ]);
    }

    public function store(StoreCommissionSettlementRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', CommissionSettlement::class);

        $settlement = $this->settlements->close($request->user(), $request->validated());

        return (new CommissionSettlementResource($settlement->load(CommissionSettlementResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Período fechado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Listagem completa de fechamentos da clínica — visão de gestão, só do dono (regra de
     * negócio 5: "funcionário só vê a própria comissão"). O extrato pessoal do funcionário é
     * `GET me/commissions`, endpoint separado que nunca aceita `staff_id` de fora.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('create', CommissionSettlement::class);

        $settlements = $this->scopedQuery($request->user())
            ->with(CommissionSettlementResource::RESOURCE_RELATIONS)
            ->orderByDesc('period_to')
            ->get();

        return CommissionSettlementResource::collection($settlements);
    }

    public function show(Request $request, int $id): CommissionSettlementResource
    {
        $settlement = $this->findForUser($request->user(), $id, 'view');

        return new CommissionSettlementResource($settlement);
    }

    public function pay(PayCommissionSettlementRequest $request, int $id): CommissionSettlementResource
    {
        $settlement = $this->findForUser($request->user(), $id, 'manage');

        return new CommissionSettlementResource(
            $this->settlements->pay($settlement, $request->validated())->load(CommissionSettlementResource::RESOURCE_RELATIONS)
        );
    }

    /** @return array{total: string, items: list<array<string, mixed>>} */
    private function pendingDeductions(int $staffId): array
    {
        $clawbacks = $this->deductions->pendingFor($staffId);

        return [
            'total' => Money::sum($clawbacks->map(fn (CommissionClawback $clawback): Money => $clawback->pendingAmount()))->toString(),
            'items' => $clawbacks->map(fn (CommissionClawback $clawback): array => [
                'clawback_id' => $clawback->id,
                'sale_item_id' => $clawback->sale_item_id,
                'sale_id' => $clawback->saleItem?->sale_id,
                'description' => $clawback->saleItem?->description,
                'reason' => $clawback->reason,
                'amount' => (string) $clawback->amount,
                'pending_amount' => $clawback->pendingAmount()->toString(),
                'created_at' => $clawback->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function findForUser(User $user, int $id, string $ability): CommissionSettlement
    {
        $settlement = $this->scopedQuery($user)
            ->with(CommissionSettlementResource::RESOURCE_RELATIONS)
            ->findOrFail($id);

        Gate::forUser($user)->authorize($ability, $settlement);

        return $settlement;
    }

    /**
     * @return Builder<CommissionSettlement>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(CommissionSettlement::query(), $user);
    }
}
