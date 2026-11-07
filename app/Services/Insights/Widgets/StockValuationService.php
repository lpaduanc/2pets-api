<?php

namespace App\Services\Insights\Widgets;

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockValuationSnapshot;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Estoque valorizado (PLANO 3.6): custo = Σ estoque × custo médio; venda = Σ estoque × preço —
 * só produto que controla estoque e com saldo POSITIVO (saldo negativo é inconsistência, não
 * valor). A soma é exata no banco e o arredondamento para centavo acontece uma vez, no total.
 *
 * Escopo: organização, ou o vet volante sem organização (`professional_id` e
 * `organization_id` nulo), o mesmo recorte do `CommercialScopeResolver`.
 */
final class StockValuationService
{
    /** @return array{cost_value: string, sale_value: string, product_count: int} */
    public function current(?int $organizationId, ?int $professionalId): array
    {
        $row = $this->scoped($organizationId, $professionalId)
            ->where('controls_stock', true)
            ->where('stock_quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(stock_quantity * COALESCE(average_cost, 0)), 0) AS cost, COALESCE(SUM(stock_quantity * COALESCE(price, 0)), 0) AS sale, COUNT(*) AS products')
            ->toBase()->first();

        return ['cost_value' => Money::of((string) $row->cost)->toString(), 'sale_value' => Money::of((string) $row->sale)->toString(), 'product_count' => (int) $row->products];
    }

    /** Grava (ou regrava) a foto do dia — idempotente: rodar de novo no mesmo dia atualiza a mesma linha. */
    public function snapshot(?int $organizationId, ?int $professionalId, CarbonImmutable $date): StockValuationSnapshot
    {
        $values = $this->current($organizationId, $professionalId);

        return DB::transaction(function () use ($organizationId, $professionalId, $date, $values): StockValuationSnapshot {
            $existing = $this->snapshotsOf($organizationId, $professionalId)->whereDate('snapshot_date', $date->toDateString())->lockForUpdate()->first();

            if ($existing !== null) {
                $existing->update($values);

                return $existing;
            }

            return StockValuationSnapshot::create($values + ['organization_id' => $organizationId, 'professional_id' => $organizationId === null ? $professionalId : null, 'snapshot_date' => $date->toDateString()]);
        });
    }

    /** Foto do último dia do mês anterior a `$today`; `null` se não houver. */
    public function previousMonthClose(?int $organizationId, ?int $professionalId, CarbonImmutable $today): ?StockValuationSnapshot
    {
        return $this->snapshotsOf($organizationId, $professionalId)
            ->whereDate('snapshot_date', $today->startOfMonth()->subDay()->toDateString())
            ->first();
    }

    /** @return list<array{organization_id: int|null, professional_id: int|null}> */
    public function scopesWithStock(): array
    {
        $organizations = Organization::query()->whereIn('id', Product::query()->whereNotNull('organization_id')->select('organization_id'))->pluck('id')
            ->map(fn (int $id): array => ['organization_id' => $id, 'professional_id' => null]);
        $freelancers = Product::query()->whereNull('organization_id')->whereNotNull('professional_id')->distinct()->pluck('professional_id')
            ->map(fn (int $id): array => ['organization_id' => null, 'professional_id' => $id]);

        return $organizations->concat($freelancers)->values()->all();
    }

    /** @return Builder<Product> */
    private function scoped(?int $organizationId, ?int $professionalId): Builder
    {
        return $organizationId !== null
            ? Product::query()->where('organization_id', $organizationId)
            : Product::query()->whereNull('organization_id')->where('professional_id', $professionalId);
    }

    /** @return Builder<StockValuationSnapshot> */
    private function snapshotsOf(?int $organizationId, ?int $professionalId): Builder
    {
        return $organizationId !== null
            ? StockValuationSnapshot::query()->where('organization_id', $organizationId)
            : StockValuationSnapshot::query()->whereNull('organization_id')->where('professional_id', $professionalId);
    }
}
