<?php

namespace App\Services\Insights;

use Illuminate\Support\Collection;

/**
 * As 6 métricas do BI de vendas, sempre calculadas juntas (regra de negócio 1 da spec: trocar
 * de métrica no front não pode disparar rota nova). Uma linha SQL única
 * (`App\Services\Insights\InsightsSalesScope::scopedSaleItems()` já entrega `sale_items`
 * filtrado; aqui só soma), reaproveitada por série, totais e resumo do drill-down — para os
 * três lerem exatamente a mesma fórmula.
 *
 * "Líquido" aqui é o total do ITEM já com o desconto do próprio item aplicado
 * (`sale_items.total`); o desconto lançado no CABEÇALHO da venda (`sales.discount_amount`,
 * mais raro no fluxo de balcão — `SaleService` aplica desconto por item na maioria dos casos)
 * não é rateado entre os itens neste indicador no MVP. Documentado em
 * docs/gap-simplesvet/contratos/20-contrato-api.md §Limitação conhecida.
 */
final class InsightMetricsAggregator
{
    public const SQL = '
        COUNT(DISTINCT sale_items.sale_id) AS sale_count,
        COALESCE(SUM(sale_items.total), 0) AS net_sales,
        COALESCE(SUM(sale_items.quantity * sale_items.unit_price), 0) AS gross_sales,
        COALESCE(SUM(sale_items.discount), 0) AS discounts,
        COALESCE(SUM(sale_items.quantity), 0) AS item_count
    ';

    /**
     * @return array{net_sales: float, gross_sales: float, discounts: float, sale_count: int, item_count: float, avg_ticket: float}
     */
    public static function fromRow(object $row): array
    {
        $netSales = round((float) $row->net_sales, 2);
        $saleCount = (int) $row->sale_count;

        return [
            'net_sales' => $netSales,
            'gross_sales' => round((float) $row->gross_sales, 2),
            'discounts' => round((float) $row->discounts, 2),
            'sale_count' => $saleCount,
            'item_count' => (float) $row->item_count,
            'avg_ticket' => self::averageTicket($netSales, $saleCount),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{net_sales: float, gross_sales: float, discounts: float, sale_count: int, item_count: float, avg_ticket: float}
     */
    public static function totalsFrom(Collection $rows): array
    {
        return self::fromRow((object) [
            'sale_count' => $rows->sum('sale_count'),
            'net_sales' => $rows->sum('net_sales'),
            'gross_sales' => $rows->sum('gross_sales'),
            'discounts' => $rows->sum('discounts'),
            'item_count' => $rows->sum('item_count'),
        ]);
    }

    /** Ticket médio = líquido ÷ nº de vendas, `0` explícito sem vendas — regra de negócio 1. */
    private static function averageTicket(float $netSales, int $saleCount): float
    {
        if ($saleCount === 0) {
            return 0.0;
        }

        return round($netSales / $saleCount, 2);
    }
}
