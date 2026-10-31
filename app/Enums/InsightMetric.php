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

    // Rentabilidade (PLANO 1.6), lidas de `sale_item_profitability` — só venda paga.
    case NET_REVENUE = 'net_revenue';
    case CARD_FEES = 'card_fees';
    case TAXES = 'taxes';
    case DISCOUNT_PERCENT = 'discount_percent';
    case COST = 'cost';
    case COMMISSIONS = 'commissions';
    case CONTRIBUTION_MARGIN = 'contribution_margin';
    case MARGIN_PERCENT = 'margin_percent';

    /**
     * Métrica que revela custo/margem: só com `reports.profitability` (recepção não vê custo,
     * PLANO 1.5/1.6). Pedir uma delas sem a permissão é 403; sem pedir, ela só não vem.
     */
    public function revealsCost(): bool
    {
        return in_array($this, self::costMetrics(), true);
    }

    /** @return list<self> */
    public static function costMetrics(): array
    {
        return [self::COST, self::COMMISSIONS, self::CONTRIBUTION_MARGIN, self::MARGIN_PERCENT];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
