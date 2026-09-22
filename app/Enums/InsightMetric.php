<?php

namespace App\Enums;

/**
 * `metric` de `GET insights/{indicator}` — contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * O endpoint sempre devolve TODAS as métricas em cada ponto da série (ver
 * `App\Services\Insights\SalesInsightsService`): trocar de líquido para ticket médio no
 * mesmo gráfico não pode recarregar a página nem chamar rota nova (critério de aceite). Este
 * enum existe para o front indicar qual métrica quer em destaque e para validar o parâmetro
 * — não para filtrar o que o backend calcula.
 */
enum InsightMetric: string
{
    case NET_SALES = 'net_sales';
    case GROSS_SALES = 'gross_sales';
    case AVG_TICKET = 'avg_ticket';
    case DISCOUNTS = 'discounts';
    case SALE_COUNT = 'sale_count';
    case ITEM_COUNT = 'item_count';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
