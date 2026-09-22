<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\AbcClass;
use App\Enums\ClientLifecycleStage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\ClientRelationshipProfileResource;
use App\Models\ClientRelationshipProfile;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Crm\ClientRelationshipProfileRecalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * `reports/client-ranking-abc` e `reports/client-lifecycle-distribution` — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Ambos disparam o recálculo lazy
 * antes de ler (`ensureFreshFor`), nunca leem um cache que pode ter mais de 24h sem tentar
 * atualizar primeiro.
 */
class ClientInsightsReportController extends Controller
{
    private const DEFAULT_WINDOW_DAYS = 365;

    /** @var list<int> */
    private const VALID_WINDOWS = [365, 90, 30];

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly ClientRelationshipProfileRecalculator $recalculator,
    ) {}

    public function abcRanking(Request $request): JsonResponse
    {
        $this->recalculator->ensureFreshFor($request->user());

        $windowDays = $this->windowDaysFrom($request);
        $profiles = $this->scopedProfiles($request)->orderBy('abc_position')->get();

        return response()->json([
            'summary' => $this->abcSummary($profiles, $windowDays),
            'data' => ClientRelationshipProfileResource::collection($profiles)->resolve($request),
        ]);
    }

    public function lifecycleDistribution(Request $request): JsonResponse
    {
        $this->recalculator->ensureFreshFor($request->user());

        $profiles = $this->scopedProfiles($request)->get();
        $total = $profiles->count();

        return response()->json([
            'total' => $total,
            'distribution' => $this->lifecycleSummary($profiles, $total),
        ]);
    }

    private function scopedProfiles(Request $request)
    {
        return $this->scope->scopeQuery(ClientRelationshipProfile::query(), $request->user())
            ->notArchived()
            ->with('client:id,name,email,phone');
    }

    private function windowDaysFrom(Request $request): int
    {
        $requested = (int) $request->query('window', self::DEFAULT_WINDOW_DAYS);

        return in_array($requested, self::VALID_WINDOWS, true) ? $requested : self::DEFAULT_WINDOW_DAYS;
    }

    /**
     * @param  Collection<int, ClientRelationshipProfile>  $profiles
     * @return array<string, array{count: int, total_spent: float}>
     */
    private function abcSummary(Collection $profiles, int $windowDays): array
    {
        $column = "total_spent_{$windowDays}d";

        return collect(AbcClass::cases())->mapWithKeys(function (AbcClass $class) use ($profiles, $column): array {
            $inClass = $profiles->filter(fn (ClientRelationshipProfile $profile) => $profile->abc_class === $class);

            return [$class->value => [
                'count' => $inClass->count(),
                'total_spent' => round((float) $inClass->sum($column), 2),
            ]];
        })->all();
    }

    /**
     * @param  Collection<int, ClientRelationshipProfile>  $profiles
     * @return array<string, array{count: int, percent: float}>
     */
    private function lifecycleSummary(Collection $profiles, int $total): array
    {
        return collect(ClientLifecycleStage::cases())->mapWithKeys(function (ClientLifecycleStage $stage) use ($profiles, $total): array {
            $count = $profiles->filter(fn (ClientRelationshipProfile $profile) => $profile->lifecycle_stage === $stage)->count();

            return [$stage->value => [
                'count' => $count,
                'percent' => $total === 0 ? 0.0 : round(($count / $total) * 100, 1),
            ]];
        })->all();
    }
}
