<?php

namespace App\Services\Finance\Managerial;

/**
 * Por qual dimensão as fontes de venda da DRE gerencial (vendas e sessões de pacote) agrupam:
 * centro de custo efetivo (2.4), tipo do item (`products`/`services`, para as metas da 2.5) ou
 * profissional do item (`staff_id`). Chave vazia vira `ManagerialCostCenters::UNALLOCATED`.
 */
enum ManagerialSalesDimension: string
{
    case COST_CENTER = 'cost_center';
    case ITEM_TYPE = 'item_type';
    case STAFF = 'staff';

    public const PRODUCTS = 'products';

    public const SERVICES = 'services';
}
