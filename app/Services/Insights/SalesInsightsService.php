<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightDimension;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

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
    public function __construct(
        private readonly InsightsSalesScope $scope,
        private readonly InsightAbcClassifier $abc,
    ) {}

    /**
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function series(User $user, InsightQuery $query): array
    {
        // `bucket_description`: rótulo de reserva do produto/serviço apagado (PLANO 1.6) — vem na
        // mesma query; o nome atual do cadastro é UMA consulta extra (`labels`).
        $rows = $this->scope->scopedSaleItems($user, $query)
            ->selectRaw(InsightBucketExpression::sql($query).' AS bucket, MAX(sale_items.description) AS bucket_description, '.InsightMetricsAggregator::SQL)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $labels = $this->labels($rows, $query->dimension);
        $series = $rows->map(fn (object $row): array => $this->point($row, $query, $labels))->all();

        return [
            'series' => $query->abc ? $this->withAbc($series) : $series,
            'totals' => InsightMetricsAggregator::totalsFrom($rows, $query->includeProfitability),
        ];
    }

    /**
     * Curva ABC por MC (PLANO 1.6) em cada ponto — a ordem da série não muda.
     *
     * @param  list<array<string, mixed>>  $series
     * @return list<array<string, mixed>>
     */
    private function withAbc(array $series): array
    {
        $classes = $this->abc->classify(array_map(fn (array $point): array => ['bucket' => $point['bucket'], 'contribution_margin' => $point['contribution_margin']], $series));

        return array_map(fn (array $point): array => $point + ['abc' => $classes[(string) $point['bucket']]], $series);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  Collection<int, string>|null  $labels
     * @return array<string, mixed>
     */
    private function point(object $row, InsightQuery $query, ?Collection $labels): array
    {
        $point = ['bucket' => $this->bucketLabel($row->bucket, $query->dimension)];

        if ($labels !== null) {
            $point['label'] = $labels->get((int) $row->bucket) ?? $row->bucket_description;
        }

        return $point + InsightMetricsAggregator::fromRow($row, $query->includeProfitability);
    }

    /**
     * Nome ATUAL do produto/serviço de cada bucket, numa consulta só; cadastro apagado cai na
     * descrição da linha de venda. Só nas dimensões `product` e `service` (PLANO 1.6).
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, string>|null
     */
    private function labels(Collection $rows, InsightDimension $dimension): ?Collection
    {
        $model = match ($dimension) {
            InsightDimension::PRODUCT => Product::class,
            InsightDimension::SERVICE => Service::class,
            default => null,
        };

        if ($model === null) {
            return null;
        }

        return $model::query()->whereIn('id', $rows->pluck('bucket')->filter()->map(fn ($id): int => (int) $id))->pluck('name', 'id');
    }

    private function bucketLabel(mixed $value, InsightDimension $dimension): string|int|null
    {
        return match ($dimension) {
            InsightDimension::DATE => CarbonImmutable::parse((string) $value)->toDateString(),
            InsightDimension::WEEKDAY => (int) $value,
            InsightDimension::EMPLOYEE,
            InsightDimension::PRODUCT,
            InsightDimension::GROUP,
            InsightDimension::BRAND,
            InsightDimension::SERVICE => $value === null ? null : (int) $value,
            InsightDimension::ITEM_TYPE => $value === Product::class ? 'product' : 'service',
        };
    }
}
