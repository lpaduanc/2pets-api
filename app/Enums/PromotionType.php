<?php

namespace App\Enums;

/** `promotions.type` (PLANO 3.2). */
enum PromotionType: string
{
    case BUY_X_PAY_Y = 'buy_x_pay_y';
    case BUNDLE = 'bundle';
    case CATEGORY_DISCOUNT = 'category_discount';
    case BRAND_DISCOUNT = 'brand_discount';

    public function label(): string
    {
        return match ($this) {
            self::BUY_X_PAY_Y => 'Leve X, pague Y',
            self::BUNDLE => 'Venda casada',
            self::CATEGORY_DISCOUNT => 'Desconto por categoria',
            self::BRAND_DISCOUNT => 'Desconto por marca',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
