<?php

namespace App\Enums;

/**
 * Atalhos "Performance de produtos" / "Performance de serviços" do BI de vendas
 * (docs/gap-petflow/PLANO.md, 1.6): dimensão + métricas + ABC por MC. Constantes do back,
 * devolvidas em `meta.presets` de `GET insights/sales` para quem vê custo; o front grava como
 * filtro salvo pelo `favorite-indicators` que já existe.
 */
enum InsightPreset: string
{
    case PRODUCT_PERFORMANCE = 'product_performance';
    case SERVICE_PERFORMANCE = 'service_performance';

    /** @return array{key: string, label: string, dimension: string, metrics: list<string>, abc: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->value,
            'label' => $this->label(),
            'dimension' => $this->dimension()->value,
            'metrics' => array_map(fn (InsightMetric $metric): string => $metric->value, $this->metrics()),
            'abc' => InsightMetric::CONTRIBUTION_MARGIN->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT_PERFORMANCE => 'Performance de produtos',
            self::SERVICE_PERFORMANCE => 'Performance de serviços',
        };
    }

    public function dimension(): InsightDimension
    {
        return match ($this) {
            self::PRODUCT_PERFORMANCE => InsightDimension::PRODUCT,
            self::SERVICE_PERFORMANCE => InsightDimension::SERVICE,
        };
    }

    /** @return list<InsightMetric> */
    public function metrics(): array
    {
        return match ($this) {
            self::PRODUCT_PERFORMANCE => [InsightMetric::NET_REVENUE, InsightMetric::COST, InsightMetric::CONTRIBUTION_MARGIN, InsightMetric::MARGIN_PERCENT, InsightMetric::DISCOUNT_PERCENT, InsightMetric::ITEM_COUNT],
            self::SERVICE_PERFORMANCE => [InsightMetric::NET_REVENUE, InsightMetric::COST, InsightMetric::COMMISSIONS, InsightMetric::CONTRIBUTION_MARGIN, InsightMetric::MARGIN_PERCENT, InsightMetric::SALE_COUNT],
        };
    }
}
