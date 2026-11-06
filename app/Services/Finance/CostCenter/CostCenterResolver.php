<?php

namespace App\Services\Finance\CostCenter;

use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Centro de custo EFETIVO de produto e serviço (PLANO 2.4), num lugar só: o do próprio cadastro;
 * vazio, o do grupo direto (`product_group_id`); vazio também, nenhum ("Não rateado"). Só o
 * grupo direto — o grupo pai não é percorrido (decisão: herança de um nível, previsível na tela
 * e barata em SQL).
 *
 * A regra existe como SQL (`saleItemExpression()`, usada pela DRE gerencial) e é a mesma que
 * `forServices()`/`forProducts()` executam — nenhuma conta paralela em PHP.
 */
final class CostCenterResolver
{
    /**
     * Expressão SQL do centro efetivo de `sale_items` (a query precisa ter `sale_items`), com os
     * bindings dos dois tipos — nome de classe vai por binding, não colado no SQL.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function saleItemExpression(): array
    {
        return [
            sprintf('CASE sale_items.sellable_type WHEN ? THEN (%s) WHEN ? THEN (%s) END',
                $this->effectiveSql('products', 'sale_items.sellable_id'), $this->effectiveSql('services', 'sale_items.sellable_id')),
            [Product::class, Service::class],
        ];
    }

    /**
     * @param  list<int>  $serviceIds
     * @return array<int, int|null>
     */
    public function forServices(array $serviceIds): array
    {
        return $this->forTable('services', $serviceIds);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int|null>
     */
    public function forProducts(array $productIds): array
    {
        return $this->forTable('products', $productIds);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int|null>
     */
    private function forTable(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table.' AS x')
            ->whereIn('x.id', array_values(array_unique($ids)))
            ->selectRaw('x.id, ('.$this->effectiveSql($table, 'x.id').') AS cost_center_id')
            ->pluck('cost_center_id', 'id')
            ->map(fn ($id): ?int => $id === null ? null : (int) $id)
            ->all();
    }

    private function effectiveSql(string $table, string $idColumn): string
    {
        return "SELECT COALESCE(own.cost_center_id, grp.cost_center_id) FROM {$table} own "
            ."LEFT JOIN product_groups grp ON grp.id = own.product_group_id WHERE own.id = {$idColumn}";
    }
}
