<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightDimension;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * `GET insights/sales` — série agregada por dimensão, contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * Uma única query agregada por chamada (regra de negócio 6: nenhum indicador roda mais que um
 * número fixo de queries) — `App\Services\Insights\InsightsSalesScope::scopedSaleItems()` já
 * devolve o recorte certo, aqui só agrupa e soma.
 */
final class SalesInsightsService
{
    public function __construct(private readonly InsightsSalesScope $scope) {}

    /**
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function series(User $user, InsightQuery $query): array
    {
        $rows = $this->scope->scopedSaleItems($user, $query)
            ->selectRaw(InsightBucketExpression::sql($query).' AS bucket, '.InsightMetricsAggregator::SQL)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return [
            'series' => $rows->map(fn (object $row): array => $this->point($row, $query->dimension))->all(),
            'totals' => InsightMetricsAggregator::totalsFrom($rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function point(object $row, InsightDimension $dimension): array
    {
        return [
            'bucket' => $this->bucketLabel($row->bucket, $dimension),
        ] + InsightMetricsAggregator::fromRow($row);
    }

    private function bucketLabel(mixed $value, InsightDimension $dimension): string|int|null
    {
        return match ($dimension) {
            InsightDimension::DATE => CarbonImmutable::parse((string) $value)->toDateString(),
            InsightDimension::WEEKDAY => (int) $value,
            InsightDimension::EMPLOYEE,
            InsightDimension::PRODUCT,
            InsightDimension::GROUP,
            InsightDimension::BRAND => $value === null ? null : (int) $value,
            InsightDimension::ITEM_TYPE => $value === Product::class ? 'product' : 'service',
        };
    }
}
