<?php

namespace App\Services\Finance\Managerial;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Profitability\SaleProfitabilityService;
use App\Services\Finance\CostCenter\CostCenterResolver;
use Carbon\CarbonImmutable;

/**
 * Linhas de venda da DRE gerencial (PLANO 1.7), somadas por mês do snapshot
 * `sale_item_profitability` — o MESMO recorte das métricas de margem do BI (1.6): venda `paid`,
 * `kind = sale`, mês de `COALESCE(sold_at, created_at)`.
 *
 * Devoluções = parte devolvida do total da linha (`sale_items.total − gross_total` do snapshot,
 * que já é o que ficou com o cliente). Com isso Receita bruta − Descontos − Devoluções =
 * receita líquida do snapshot, centavo a centavo. Brinde/cortesia: receita 0, custo no CMV.
 *
 * Venda paga ainda sem snapshot (fila sem worker) é calculada antes de somar.
 */
final class ManagerialSalesTotals
{
    private const MONTH = "to_char(date_trunc('month', COALESCE(sales.sold_at, sales.created_at)), 'YYYY-MM')";

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SaleProfitabilityService $profitability,
        private readonly CostCenterResolver $costCenters,
    ) {}

    /**
     * Por centro de custo efetivo do produto/serviço (PLANO 2.4; `unallocated` sem centro) e mês.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function byCenterAndMonth(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->byDimensionAndMonth($user, $from, $to, ManagerialSalesDimension::COST_CENTER);
    }

    /**
     * Mesmas somas, agrupadas pela dimensão pedida (centro, tipo do item ou profissional) e mês —
     * é daqui que as metas de venda (PLANO 2.5) tiram o realizado, sem conta paralela.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function byDimensionAndMonth(User $user, CarbonImmutable $from, CarbonImmutable $to, ManagerialSalesDimension $dimension): array
    {
        $this->computeMissingSnapshots($user, $from, $to);
        [$centerSql, $centerBindings] = $this->dimensionExpression($dimension);

        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('sale_item_profitability', 'sale_item_profitability.sale_item_id', '=', 'sale_items.id')
            ->whereIn('sale_items.sale_id', $this->paidSales($user, $from, $to)->select('sales.id'))
            // Pacote é receita diferida (PLANO 2.7): entra sessão a sessão em `ManagerialPackageRevenue`.
            ->where('sale_items.sellable_type', '!=', ServicePackage::class)
            ->groupByRaw(self::MONTH.', cost_center')
            ->selectRaw("({$centerSql}) AS cost_center", $centerBindings)
            ->selectRaw(self::MONTH.' AS month,
                COALESCE(SUM(sale_items.quantity * sale_items.unit_price), 0) AS gross_revenue,
                COALESCE(SUM(sale_items.discount), 0) AS line_discounts,
                COALESCE(SUM(sale_item_profitability.allocated_header_discount), 0) AS header_discounts,
                COALESCE(SUM(sale_items.total - sale_item_profitability.gross_total), 0) AS returns,
                COALESCE(SUM(sale_item_profitability.tax_amount), 0) AS taxes,
                COALESCE(SUM(sale_item_profitability.net_revenue), 0) AS sales_revenue,
                COALESCE(SUM(CASE WHEN sale_items.sellable_type = ? THEN sale_item_profitability.cost_amount ELSE 0 END), 0) AS cost_products,
                COALESCE(SUM(CASE WHEN sale_items.sellable_type = ? THEN 0 ELSE sale_item_profitability.cost_amount END), 0) AS cost_services,
                COALESCE(SUM(sale_item_profitability.card_fee_share), 0) AS card_fees,
                COALESCE(SUM(sale_item_profitability.commission_amount), 0) AS commissions', [Product::class, Product::class])
            ->toBase()
            ->get();

        $byCenter = [];

        foreach ($rows as $row) {
            $byCenter[$row->cost_center === null ? ManagerialCostCenters::UNALLOCATED : (string) $row->cost_center][$row->month]
                = array_map('strval', array_diff_key((array) $row, ['month' => true, 'cost_center' => true]));
        }

        return $byCenter;
    }

    /** @return array{0: string, 1: list<string>} */
    private function dimensionExpression(ManagerialSalesDimension $dimension): array
    {
        return match ($dimension) {
            ManagerialSalesDimension::COST_CENTER => $this->costCenters->saleItemExpression(),
            ManagerialSalesDimension::ITEM_TYPE => ['CASE WHEN sale_items.sellable_type = ? THEN ? ELSE ? END', [Product::class, ManagerialSalesDimension::PRODUCTS, ManagerialSalesDimension::SERVICES]],
            ManagerialSalesDimension::STAFF => ['sale_items.staff_id', []],
            ManagerialSalesDimension::TYPE_AND_GROUP => [
                "CASE WHEN sale_items.sellable_type = ? THEN 'products:' || COALESCE((SELECT p.product_group_id::text FROM products p WHERE p.id = sale_items.sellable_id), '') "
                ."ELSE 'services:' || COALESCE((SELECT s.product_group_id::text FROM services s WHERE s.id = sale_items.sellable_id), '') END",
                [Product::class],
            ],
        };
    }

    private function paidSales(User $user, CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Database\Eloquent\Builder
    {
        return $this->scope->scopeQuery(Sale::query(), $user)
            ->where('sales.kind', SaleKind::SALE->value)
            ->where('sales.status', SaleStatus::PAID->value)
            ->whereRaw('COALESCE(sales.sold_at, sales.created_at) BETWEEN ? AND ?', [$from->startOfDay(), $to->endOfDay()]);
    }

    private function computeMissingSnapshots(User $user, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $this->paidSales($user, $from, $to)
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($items) => $items->whereHas('profitability'))
            ->each(fn (Sale $sale) => $this->profitability->recompute($sale));
    }
}
