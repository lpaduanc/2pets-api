<?php

namespace App\Enums;

/**
 * Faixa de orçamento mensal que a empresa parceira planeja investir no benefício pet
 * (`CompleteProfileCompany.vue` — select `budget_range`, opcional).
 */
enum BudgetRange: string
{
    case UP_TO_5K = 'up_to_5k';
    case FROM_5K_TO_15K = '5k_15k';
    case FROM_15K_TO_50K = '15k_50k';
    case ABOVE_50K = 'above_50k';
    case TO_BE_DEFINED = 'tbd';

    public function label(): string
    {
        return match ($this) {
            self::UP_TO_5K => 'Até R$ 5.000/mês',
            self::FROM_5K_TO_15K => 'R$ 5.000 a R$ 15.000/mês',
            self::FROM_15K_TO_50K => 'R$ 15.000 a R$ 50.000/mês',
            self::ABOVE_50K => 'Acima de R$ 50.000/mês',
            self::TO_BE_DEFINED => 'A definir',
        };
    }
}
