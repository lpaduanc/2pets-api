<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * O recorte comum de todo indicador de vendas — escopo comercial + período + tipo (venda ou
 * orçamento, regra de negócio 4 da spec: nunca reimplementar `CommercialScopeResolver` aqui) +
 * filtros. Único ponto que monta a query de `sales`/`sale_items` — série, drill-down, export e
 * produtividade chamam daqui, nunca escrevem o próprio `WHERE`.
 */
final class InsightsSalesScope
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /** @return Builder<Sale> */
    public function scopedSales(User $user, InsightQuery $query): Builder
    {
        $sales = $this->scope->scopeQuery(Sale::query(), $user)
            ->where('sales.kind', $query->kind->value)
            ->where('sales.status', '!=', SaleStatus::CANCELLED->value)
            ->whereRaw('COALESCE(sales.sold_at, sales.created_at) BETWEEN ? AND ?', [$query->from, $query->to]);

        if ($query->clientIds !== []) {
            $sales->whereIn('sales.client_id', $query->clientIds);
        }

        return $sales;
    }

    /**
     * Itens das vendas acima, já `JOIN`ados com `sales` (necessário para a data/o cliente do
     * cabeçalho) — base de toda dimensão (`date`/`weekday`/`employee`/`item_type`/`product`/
     * `group`/`brand`).
     *
     * @return Builder<SaleItem>
     */
    public function scopedSaleItems(User $user, InsightQuery $query): Builder
    {
        $saleIds = $this->scopedSales($user, $query)->select('sales.id');

        $items = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sale_items.sale_id', $saleIds);

        if ($query->employeeIds !== []) {
            $items->whereIn('sale_items.staff_id', $query->employeeIds);
        }

        if ($query->dimension->requiresProductJoin()) {
            $this->joinProducts($items);
        }

        return $items;
    }

    /**
     * `product`/`group`/`brand` só existem para item que É produto — item de serviço não tem
     * grupo/marca, e um `LEFT JOIN` deixaria ele vazar como bucket `null` misturado com "sem
     * grupo"/"sem marca" de verdade. `WHERE sellable_type` primeiro filtra para só produto;
     * o `JOIN` (não `LEFT JOIN`) então é seguro — todo `sellable_id` restante é um id válido
     * de `products`.
     *
     * @param  Builder<SaleItem>  $items
     */
    private function joinProducts(Builder $items): void
    {
        $items->where('sale_items.sellable_type', Product::class)
            ->join('products', 'products.id', '=', 'sale_items.sellable_id');
    }
}
