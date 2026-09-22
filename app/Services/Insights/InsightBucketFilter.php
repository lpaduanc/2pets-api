<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightDimension;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restringe uma query de `sale_items` (já escopada por `InsightsSalesScope`) a UM ponto
 * específico do gráfico — o `bucket` que o front recebeu na série e devolve no drill-down.
 * Reusa a mesma expressão de `InsightBucketExpression`, nunca uma comparação equivalente
 * escrita à mão, para não correr o risco das duas divergirem (ver regra de negócio 2).
 */
final class InsightBucketFilter
{
    private const UNASSIGNED = 'unassigned';

    /** @param  Builder<SaleItem>  $items */
    public function apply(Builder $items, InsightQuery $query, string $bucket): Builder
    {
        $expression = InsightBucketExpression::sql($query);

        if ($bucket === self::UNASSIGNED && $this->acceptsUnassigned($query->dimension)) {
            return $items->whereNull($expression);
        }

        return $items->whereRaw("{$expression} = ?", [$this->boundValueFor($query->dimension, $bucket)]);
    }

    /** `EMPLOYEE` (vínculo opcional) e `GROUP`/`BRAND` (produto sem grupo/marca cadastrado). */
    private function acceptsUnassigned(InsightDimension $dimension): bool
    {
        return $dimension === InsightDimension::EMPLOYEE || $dimension->isNullable();
    }

    private function boundValueFor(InsightDimension $dimension, string $bucket): string|int
    {
        return match ($dimension) {
            InsightDimension::DATE => (string) CarbonImmutable::parse($bucket),
            InsightDimension::WEEKDAY, InsightDimension::EMPLOYEE,
            InsightDimension::PRODUCT, InsightDimension::GROUP, InsightDimension::BRAND => (int) $bucket,
            InsightDimension::ITEM_TYPE => $bucket === 'product' ? Product::class : Service::class,
        };
    }
}
