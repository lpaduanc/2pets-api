<?php

namespace App\Enums;

/** `sales_goals.scope` (PLANO 2.5). `cost_center` e `staff` exigem `scope_id`. */
enum SalesGoalScope: string
{
    case TOTAL = 'total';
    case PRODUCTS = 'products';
    case SERVICES = 'services';
    case COST_CENTER = 'cost_center';
    case STAFF = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::TOTAL => 'Total',
            self::PRODUCTS => 'Produtos',
            self::SERVICES => 'Serviços',
            self::COST_CENTER => 'Centro de custo',
            self::STAFF => 'Profissional',
        };
    }

    public function needsScopeId(): bool
    {
        return in_array($this, [self::COST_CENTER, self::STAFF], true);
    }
}
