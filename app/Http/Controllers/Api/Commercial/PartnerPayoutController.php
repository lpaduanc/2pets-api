<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\PartnerPayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\ReconcilePartnerPayoutRequest;
use App\Http\Requests\Commercial\StorePartnerPayoutRequest;
use App\Http\Resources\Commercial\PartnerPayoutResource;
use App\Models\PartnerPayout;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\PartnerCandidateFinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Repasse a parceiro terceiro — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de negócio 7.
 * Registro e conciliação manuais, sem cálculo automático (diferente de `CommissionRule`).
 */
class PartnerPayoutController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PartnerCandidateFinder $candidates,
    ) {}

    /**
     * Busca escopada para o autocomplete de `partner_user_id` (achado do frontend) — nunca a
     * base de usuários inteira da plataforma. Ver `PartnerCandidateFinder`.
     */
    public function candidates(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', PartnerPayout::class);

        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json([
            'data' => $this->candidates->search($request->user(), $request->string('q')->trim()->toString()),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // A rota já exige `permission:partner-payouts.manage` (item 22, passada final) — o
        // Gate continua aqui de propósito: o middleware só decide "pode tocar a feature",
        // quem decide "próprio × qualquer" (aqui, nem se aplica — é tudo-ou-nada) continua
        // sendo a Policy, mesma régua de `store`/`reconcile`.
        Gate::forUser($request->user())->authorize('create', PartnerPayout::class);

        $payouts = $this->scopedQuery($request->user())
            ->with(PartnerPayoutResource::RESOURCE_RELATIONS)
            ->orderByDesc('created_at')
            ->get();

        return PartnerPayoutResource::collection($payouts);
    }

    public function store(StorePartnerPayoutRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', PartnerPayout::class);

        // `status` explícito (não só o `default` da migration): sem isto o model em memória
        // fica com o atributo ausente logo após o `create()` — a coluna nasce `pending` no
        // banco, mas `PartnerPayoutResource::toArray()` lê `$this->status->value` do objeto
        // ANTES de qualquer reload, e quebrava com "Attempt to read property on null".
        $payout = PartnerPayout::create($request->validated() + $this->scope->ownershipFor($request->user()) + [
            'status' => PartnerPayoutStatus::PENDING,
        ]);

        return (new PartnerPayoutResource($payout->load(PartnerPayoutResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Repasse registrado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function reconcile(ReconcilePartnerPayoutRequest $request, int $id): PartnerPayoutResource
    {
        $payout = $this->scopedQuery($request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('manage', $payout);

        abort_if($payout->status !== PartnerPayoutStatus::PENDING, 422, 'Este repasse já foi conciliado ou cancelado.');

        $payout->update([
            'status' => PartnerPayoutStatus::RECONCILED,
            'reconciled_at' => now(),
            'reconciled_by' => $request->user()->id,
            'payment_method' => $request->validated('payment_method'),
            'payment_reference' => $request->validated('payment_reference'),
        ]);

        return new PartnerPayoutResource($payout->fresh(PartnerPayoutResource::RESOURCE_RELATIONS));
    }

    /**
     * @return Builder<PartnerPayout>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(PartnerPayout::query(), $user);
    }
}
