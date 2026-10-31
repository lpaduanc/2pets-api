<?php

namespace App\Services\Commercial\Pricing;

/**
 * O que mudou na venda e onde o 422 aponta (PLANO 1.4):
 *  - desconto do cabeçalho → `discount_value` (o campo do `POST sales/{id}/discount`);
 *  - linha sendo LANÇADA → `discount` / `unit_price`, os campos do próprio `POST .../items` (a
 *    linha é desfeita pela transação e não tem id para apontar);
 *  - qualquer outra linha → `items.{sale_items.id}.discount` / `items.{sale_items.id}.unit_price`.
 */
enum SalePricingChange
{
    case ITEM;
    case HEADER_DISCOUNT;
    case FINALIZATION;

    /** @param  'discount'|'unit_price'  $column */
    public function field(string $column, int $itemId, ?int $addedItemId): string
    {
        if ($this === self::HEADER_DISCOUNT) {
            return 'discount_value';
        }

        return $itemId === $addedItemId ? $column : "items.{$itemId}.{$column}";
    }
}
