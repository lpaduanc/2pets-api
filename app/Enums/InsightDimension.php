<?php

namespace App\Enums;

/**
 * `dimension` de `GET insights/{indicator}` — contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * `product`/`group`/`brand` fecham o MVP nesta passada final (ver contrato
 * docs/gap-simplesvet/contratos/20-contrato-api.md) — as três só fazem sentido para item de
 * venda que É produto (`sale_items.sellable_type = Product`), nunca serviço; por isso exigem
 * o `JOIN` extra com `products` que `requiresProductJoin()` sinaliza para
 * `InsightsSalesScope::scopedSaleItems()`.
 */
enum InsightDimension: string
{
    case DATE = 'date';
    case WEEKDAY = 'weekday';
    case EMPLOYEE = 'employee';
    case ITEM_TYPE = 'item_type';
    case PRODUCT = 'product';
    case GROUP = 'group';
    case BRAND = 'brand';

    /**
     * `product`/`group`/`brand` só existem na tabela `products` — um item de serviço não tem
     * grupo/marca, então a query precisa do `JOIN` (e do filtro `sellable_type = Product`)
     * para essas três, e NUNCA para as outras quatro (que já funcionam sem tocar `products`).
     */
    public function requiresProductJoin(): bool
    {
        return match ($this) {
            self::PRODUCT, self::GROUP, self::BRAND => true,
            self::DATE, self::WEEKDAY, self::EMPLOYEE, self::ITEM_TYPE => false,
        };
    }

    /** Coluna anulável (`products.product_group_id`/`brand_id`) — "sem grupo/marca" é um bucket válido. */
    public function isNullable(): bool
    {
        return match ($this) {
            self::GROUP, self::BRAND => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
