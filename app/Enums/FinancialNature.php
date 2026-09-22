<?php

namespace App\Enums;

/**
 * Natureza contábil — `financial_categories.nature` e `financial_entries.nature`. Contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * A mesma natureza vale para categoria e lançamento de propósito: uma categoria de despesa só
 * aceita lançamento de despesa — validado em `FinancialEntryService::create()`.
 */
enum FinancialNature: string
{
    case REVENUE = 'revenue';
    case EXPENSE = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::REVENUE => 'Receita',
            self::EXPENSE => 'Despesa',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
