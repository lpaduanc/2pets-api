<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET insights/{indicator}/drill-down` — as vendas (na verdade, os ITENS de venda, mesma
 * granularidade da série) que compõem um ponto do gráfico, paginadas.
 *
 * `summary` é calculado com a MESMA agregação SQL da série (`InsightMetricsAggregator`) sobre
 * a query INTEIRA (não sobre a página atual) — é a prova de que "a soma do drill-down bate
 * com o valor do gráfico" independe de paginação (critério de aceite mais importante da spec).
 */
final class InsightsDrillDownService
{
    private const RELATIONS = ['sale.client:id,name', 'sale.pet:id,name', 'sellable', 'staff.user:id,name'];

    public function __construct(
        private readonly InsightsSalesScope $scope,
        private readonly InsightBucketFilter $bucketFilter,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, summary: array<string, mixed>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function paginate(User $user, InsightQuery $query, ?string $bucket, int $page, int $perPage): array
    {
        $items = $this->filteredItems($user, $query, $bucket);

        $summaryRow = (clone $items)->selectRaw(InsightMetricsAggregator::SQL)->first();
        $total = (clone $items)->count('sale_items.id');

        // `sale_items.*`: sem isto, o `JOIN` com `sales` faz `SELECT *` ambíguo (as duas
        // tabelas têm `id`/`created_at`/`updated_at`) e o `id` do item hidrata errado.
        $rows = (clone $items)->select('sale_items.*')->with(self::RELATIONS)
            ->orderByDesc('sales.sold_at')
            ->orderByDesc('sale_items.id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $rows->map(fn (SaleItem $item): array => $this->row($item))->all(),
            'summary' => InsightMetricsAggregator::fromRow($summaryRow),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /** @return Builder<SaleItem> */
    public function filteredItems(User $user, InsightQuery $query, ?string $bucket): Builder
    {
        $items = $this->scope->scopedSaleItems($user, $query);

        return $bucket === null ? $items : $this->bucketFilter->apply($items, $query, $bucket);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SaleItem $item): array
    {
        $sale = $item->sale;

        return [
            'sale_id' => $sale->id,
            'sale_number' => (string) ($sale->number ?? $sale->id),
            'date' => ($sale->sold_at ?? $sale->created_at)->toDateString(),
            'status' => $sale->status->value,
            'client' => $sale->client ? ['id' => $sale->client->id, 'name' => $sale->client->name] : null,
            'pet' => $sale->pet ? ['id' => $sale->pet->id, 'name' => $sale->pet->name] : null,
            'product' => $item->description,
            'employee' => $item->staff?->user ? ['id' => $item->staff->user->id, 'name' => $item->staff->user->name] : null,
            'quantity' => (float) $item->quantity,
            'gross' => round((float) $item->quantity * (float) $item->unit_price, 2),
            'discount' => (float) $item->discount,
            'net' => (float) $item->total,
        ];
    }
}
