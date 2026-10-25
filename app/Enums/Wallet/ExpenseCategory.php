<?php

namespace App\Enums\Wallet;

/**
 * Categoria de um gasto do tutor (`tutor_expenses.category`).
 *
 * O catálogo é fechado de propósito: a Carteira foi desenhada para o tutor TOCAR, não
 * digitar. Categoria + subcategoria já dizem o que foi o gasto ("Saúde · Vacina"), então a
 * descrição livre é opcional. A ordem dos cases é a ordem em que o app mostra os botões —
 * do gasto mais frequente para o menos frequente no mercado pet brasileiro.
 */
enum ExpenseCategory: string
{
    case FOOD = 'food';
    case HEALTH = 'health';
    case HYGIENE = 'hygiene';
    case SERVICES = 'services';
    case ACCESSORIES = 'accessories';
    case INSURANCE = 'insurance';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FOOD => 'Alimentação',
            self::HEALTH => 'Saúde',
            self::HYGIENE => 'Banho e higiene',
            self::SERVICES => 'Serviços',
            self::ACCESSORIES => 'Acessórios',
            self::INSURANCE => 'Plano e seguro',
            self::OTHER => 'Outros',
        };
    }

    /** Nome do ícone Colo no app do tutor (`src/constants/icons.js`). */
    public function icon(): string
    {
        return match ($this) {
            self::FOOD => 'bowl',
            self::HEALTH => 'cross',
            self::HYGIENE => 'drop',
            self::SERVICES => 'users',
            self::ACCESSORIES => 'bag',
            self::INSURANCE => 'shield',
            self::OTHER => 'tag',
        };
    }

    /** @return list<ExpenseSubcategory> */
    public function subcategories(): array
    {
        return array_values(array_filter(
            ExpenseSubcategory::cases(),
            fn (ExpenseSubcategory $subcategory) => $subcategory->category() === $this,
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
