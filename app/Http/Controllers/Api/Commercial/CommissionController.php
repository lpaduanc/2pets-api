<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\CommissionSettlementResource;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Services\Commercial\CommissionSettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Extrato PESSOAL de comissão do usuário autenticado — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 *
 * Deliberadamente sem `staff_id` no request: a lista nasce filtrada pelos PRÓPRIOS vínculos
 * (`organization_members`) de quem chamou — o mesmo padrão de `me/quotes`. É o que faz este
 * endpoint ser seguro contra IDOR mesmo sem Policy própria (não há id de fora para escapar).
 */
class CommissionController extends Controller
{
    public function __construct(private readonly CommissionSettlementService $settlements) {}

    public function myCommissions(Request $request): JsonResponse
    {
        $staffIds = $request->user()->activeOrganizationMemberships()->pluck('id');

        $settlements = CommissionSettlement::query()
            ->whereIn('staff_id', $staffIds)
            ->with(CommissionSettlementResource::RESOURCE_RELATIONS)
            ->orderByDesc('period_to')
            ->get();

        return response()->json([
            'data' => CommissionSettlementResource::collection($settlements),
            'meta' => ['open_estimate' => $this->openEstimate($request->user(), $staffIds)],
        ]);
    }

    /**
     * Soma do que fecharia HOJE em cada vínculo do usuário — só uma estimativa (o texto da
     * tela deixa isso claro, ver spec): pode mudar até o fechamento de verdade acontecer.
     *
     * @param  Collection<int, int>  $staffIds
     */
    private function openEstimate(User $user, Collection $staffIds): float
    {
        $total = $staffIds->sum(
            fn (int $staffId): float => $this->settlements->openSummary($user, $staffId, CarbonImmutable::now())->sum('commission_amount')
        );

        return round($total, 2);
    }
}
