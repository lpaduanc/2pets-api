<?php

namespace App\Services\Finance\Managerial;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Profitability\SaleProfitabilityService;
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
    ) {}

    /** @return array<string, array<string, string>> por mês `YYYY-MM` */
    public function byMonth(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->computeMissingSnapshots($user, $from, $to);

        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('sale_item_profitability', 'sale_item_profitability.sale_item_id', '=', 'sale_items.id')
            ->whereIn('sale_items.sale_id', $this->paidSales($user, $from, $to)->select('sales.id'))
            ->groupByRaw(self::MONTH)
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
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->month => array_map('strval', array_diff_key((array) $row, ['month' => true]))])
            ->all();
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
