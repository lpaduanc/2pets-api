<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightDimension;
use App\Enums\InsightGranularity;

/**
 * A expressão SQL que define um "ponto" da série, por dimensão — fonte ÚNICA usada tanto para
 * agrupar a agregação (`SalesInsightsService`) quanto para filtrar o drill-down de um ponto
 * específico (`InsightBucketFilter`). As duas NUNCA podem divergir: é o que garante a regra
 * de negócio 2 da spec ("a soma do drill-down bate exatamente com o valor do gráfico").
 */
final class InsightBucketExpression
{
    public static function sql(InsightQuery $query): string
    {
        return match ($query->dimension) {
            InsightDimension::DATE => self::dateTrunc($query->granularity),
            InsightDimension::WEEKDAY => 'EXTRACT(ISODOW FROM COALESCE(sales.sold_at, sales.created_at))',
            InsightDimension::EMPLOYEE => 'sale_items.staff_id',
            InsightDimension::ITEM_TYPE => 'sale_items.sellable_type',
            InsightDimension::PRODUCT => 'sale_items.sellable_id',
            InsightDimension::GROUP => 'products.product_group_id',
            InsightDimension::BRAND => 'products.brand_id',
        };
    }

    private static function dateTrunc(InsightGranularity $granularity): string
    {
        $unit = match ($granularity) {
            InsightGranularity::DAY => 'day',
            InsightGranularity::WEEK => 'week',
        };

        return "date_trunc('{$unit}', COALESCE(sales.sold_at, sales.created_at))";
    }
}
