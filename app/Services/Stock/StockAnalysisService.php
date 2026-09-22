<?php

namespace App\Services\Stock;

use App\Enums\StockDirection;
use App\Enums\StockMovementType;
use App\Enums\StockSituation;
use App\Models\Product;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análise de estoque — docs/gap-simplesvet/07, "o achado mais forte do módulo": classifica cada
 * produto que controla estoque numa situação e soma o capital parado (`saldo × custo médio`).
 *
 * Precedência (a primeira regra que casa vence), escolhida para que nenhum produto caia em
 * duas situações e a soma dos grupos feche com o total:
 *
 *  1. `restock`  — saldo ≤ estoque mínimo, ou cobertura < prazo do fornecedor (padrão 15 dias)
 *  2. `new`      — primeira entrada há menos de 90 dias (ainda sem histórico de giro)
 *  3. `stagnant` — nenhuma saída de venda/uso em 90 dias e saldo > 0
 *  4. `excess`   — saldo acima do máximo, ou cobertura > 180 dias
 *  5. `adequate` — o resto
 *
 * "Cobertura" = saldo ÷ saída média diária nos últimos 90 dias.
 */
final class StockAnalysisService
{
    public const WINDOW_DAYS = 90;

    public const NEW_DAYS = 90;

    public const EXCESS_COVERAGE_DAYS = 180;

    public const RESTOCK_COVERAGE_DAYS = 15;

    /** Saídas que representam giro. Perda e ajuste não são demanda. */
    private const DEMAND_TYPES = [StockMovementType::SALE_OUT, StockMovementType::INTERNAL_USE];

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * @return array<string, int>
     */
    public function criteria(): array
    {
        return [
            'window_days' => self::WINDOW_DAYS,
            'new_days' => self::NEW_DAYS,
            'excess_coverage_days' => self::EXCESS_COVERAGE_DAYS,
            'restock_coverage_days' => self::RESTOCK_COVERAGE_DAYS,
        ];
    }

    /**
     * Uma linha por produto com a situação e os números que a justificam.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function classify(User $user, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $windowStart = $now->subDays(self::WINDOW_DAYS);

        $products = $this->scope->scopeQuery(Product::query(), $user)
            ->where('controls_stock', true)
            ->where('is_active', true)
            ->with('lastSupplier:id,lead_time_days')
            ->orderBy('name')
            ->get();

        $stats = $this->movementStats($products->pluck('id'), $windowStart);

        return $products->map(function (Product $product) use ($stats, $now): array {
            $row = $stats->get($product->id);
            $stock = (int) $product->stock_quantity;
            $demand = (int) ($row->window_out ?? 0);
            $dailyOut = round($demand / self::WINDOW_DAYS, 4);
            $coverage = $dailyOut > 0 ? round(max($stock, 0) / $dailyOut, 1) : null;
            $firstIn = $row?->first_in_at ? CarbonImmutable::parse($row->first_in_at) : null;
            $lastOut = $row?->last_out_at ? CarbonImmutable::parse($row->last_out_at) : null;

            $situation = $this->situationFor($product, $stock, $coverage, $firstIn, $lastOut, $now);

            return [
                'product' => $product,
                'situation' => $situation,
                'stock_quantity' => $stock,
                'min_stock' => (int) $product->min_stock,
                'max_stock' => $product->max_stock,
                'average_cost' => (float) $product->average_cost,
                'capital' => round(max($stock, 0) * (float) $product->average_cost, 2),
                'daily_out' => $dailyOut,
                'coverage_days' => $coverage,
                'last_out_at' => $lastOut?->toIso8601String(),
                'first_in_at' => $firstIn?->toIso8601String(),
            ];
        });
    }

    /**
     * Contagem e capital por situação, mais o total. A soma dos grupos é igual ao total por
     * construção (critério de aceite).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array{count: int, value: float}>
     */
    public function summarize(Collection $rows): array
    {
        $summary = ['all' => ['count' => $rows->count(), 'value' => round($rows->sum('capital'), 2)]];

        foreach (StockSituation::cases() as $situation) {
            $group = $rows->where('situation', $situation);
            $summary[$situation->value] = ['count' => $group->count(), 'value' => round($group->sum('capital'), 2)];
        }

        return $summary;
    }

    private function situationFor(
        Product $product,
        int $stock,
        ?float $coverage,
        ?CarbonImmutable $firstIn,
        ?CarbonImmutable $lastOut,
        CarbonImmutable $now,
    ): StockSituation {
        $leadTime = $product->lastSupplier?->lead_time_days ?: self::RESTOCK_COVERAGE_DAYS;

        if ($stock <= (int) $product->min_stock || ($coverage !== null && $coverage < $leadTime)) {
            return StockSituation::RESTOCK;
        }

        $firstSeen = $firstIn ?? CarbonImmutable::parse($product->created_at);

        if ($firstSeen->gt($now->subDays(self::NEW_DAYS))) {
            return StockSituation::NEW;
        }

        if ($lastOut === null || $lastOut->lt($now->subDays(self::WINDOW_DAYS))) {
            return StockSituation::STAGNANT;
        }

        if (($product->max_stock !== null && $stock > $product->max_stock)
            || ($coverage !== null && $coverage > self::EXCESS_COVERAGE_DAYS)) {
            return StockSituation::EXCESS;
        }

        return StockSituation::ADEQUATE;
    }

    /**
     * Uma query agregada para todos os produtos — a tela lista o catálogo inteiro e não pode
     * cair em N+1.
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, object>
     */
    private function movementStats(Collection $productIds, CarbonImmutable $windowStart): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        $demand = "'".implode("','", array_map(fn (StockMovementType $t) => $t->value, self::DEMAND_TYPES))."'";
        $in = StockDirection::IN->value;

        return DB::table('stock_movements')
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw("MIN(CASE WHEN direction = '{$in}' THEN occurred_at END) AS first_in_at")
            ->selectRaw("MAX(CASE WHEN type IN ({$demand}) THEN occurred_at END) AS last_out_at")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ({$demand}) AND occurred_at >= ? THEN quantity END), 0) AS window_out", [$windowStart])
            ->get()
            ->keyBy('product_id');
    }
}
