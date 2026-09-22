<?php

namespace App\Enums;

/**
 * Situação do produto na análise de estoque — docs/gap-simplesvet/07, "o achado mais forte do
 * módulo". A regra de classificação mora em `StockAnalysisService`; aqui só o vocabulário.
 */
enum StockSituation: string
{
    case RESTOCK = 'restock';
    case EXCESS = 'excess';
    case STAGNANT = 'stagnant';
    case NEW = 'new';
    case ADEQUATE = 'adequate';

    public function label(): string
    {
        return match ($this) {
            self::RESTOCK => 'Repor',
            self::EXCESS => 'Em excesso',
            self::STAGNANT => 'Parado',
            self::NEW => 'Novos',
            self::ADEQUATE => 'Adequado',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
