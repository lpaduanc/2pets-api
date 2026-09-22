<?php

namespace App\Services\Report;

use App\Models\PetImmunizationDose;
use Carbon\CarbonInterface;

/**
 * `GET reports/immunization-adherence?from=&to=&organization_id=` — contrato spec 13.
 * Total/Aplicado/%/Pendente das doses cuja PREVISÃO (`scheduled_for`) cai no período —
 * "pendente" inclui tanto agendada no futuro quanto vencida (a % de aderência é sobre o que
 * já deveria ou já foi resolvido, vencida conta contra o número, não some da base).
 */
final class ImmunizationAdherenceService
{
    /**
     * @return array{total: int, applied: int, pending: int, adherence_percentage: float}
     */
    public function summarize(CarbonInterface $from, CarbonInterface $to, ?int $organizationId): array
    {
        $query = PetImmunizationDose::query()
            ->whereBetween('scheduled_for', [$from->toDateString(), $to->toDateString()])
            ->whereHas('plan.protocol', function ($scoped) use ($organizationId): void {
                if ($organizationId !== null) {
                    $scoped->where('organization_id', $organizationId);
                }
            });

        $total = (clone $query)->count();
        $applied = (clone $query)->whereNotNull('applied_at')->count();
        $pending = $total - $applied;

        return [
            'total' => $total,
            'applied' => $applied,
            'pending' => $pending,
            'adherence_percentage' => $total === 0 ? 0.0 : round(($applied / $total) * 100, 1),
        ];
    }
}
