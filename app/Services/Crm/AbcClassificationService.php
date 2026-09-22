<?php

namespace App\Services\Crm;

use App\Enums\AbcClass;
use App\Models\ClientRelationshipProfile;

/**
 * Classificação ABC por PERCENTIL DE CONTAGEM — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, regra de negócio 3. Os números
 * do SimplesVet (17/39/56 clientes ≈ 15%/35%/50%) batem com percentil de contagem, não com
 * Pareto de valor ("A = 80% do faturamento") — decisão explícita, fixada aqui para não ser
 * refeita como suposição diferente depois.
 *
 * Determinístico: ordena por `total_spent_365d` desc e usa `client_id` como desempate estável
 * — o mesmo dado de entrada produz sempre o mesmo resultado, mesmo na fronteira do percentil.
 */
final class AbcClassificationService
{
    private const TOP_A_PERCENTILE = 0.15;

    private const TOP_B_CUMULATIVE_PERCENTILE = 0.50;

    /**
     * Recalcula TODOS os perfis não-arquivados de um escopo comercial. Retorna quantos foram
     * classificados — `0` sem lançar exceção quando o escopo não tem nenhum perfil ainda.
     */
    public function recalculate(?int $organizationId, int $professionalId): int
    {
        $profiles = $this->rankedProfiles($organizationId, $professionalId)->get();
        $total = $profiles->count();

        if ($total === 0) {
            return 0;
        }

        $profiles->values()->each(
            fn (ClientRelationshipProfile $profile, int $index) => $this->applyRank($profile, $index + 1, $total)
        );

        return $total;
    }

    private function rankedProfiles(?int $organizationId, int $professionalId)
    {
        return ClientRelationshipProfile::query()
            ->forCommercialScope($organizationId, $professionalId)
            ->notArchived()
            ->orderByDesc('total_spent_365d')
            ->orderBy('client_id');
    }

    private function applyRank(ClientRelationshipProfile $profile, int $position, int $total): void
    {
        $profile->forceFill([
            'abc_class' => $this->classFor($position, $total)->value,
            'abc_position' => $position,
            'recalculated_at' => now(),
        ])->save();
    }

    private function classFor(int $position, int $total): AbcClass
    {
        $percentile = $position / $total;

        return match (true) {
            $percentile <= self::TOP_A_PERCENTILE => AbcClass::A,
            $percentile <= self::TOP_B_CUMULATIVE_PERCENTILE => AbcClass::B,
            default => AbcClass::C,
        };
    }
}
