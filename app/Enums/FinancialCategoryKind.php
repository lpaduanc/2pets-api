<?php

namespace App\Enums;

/**
 * `financial_categories.kind` — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * `GROUP` só agrupa (soma recursiva das filhas na árvore); só `ENTRY` (folha) recebe
 * lançamento. Um lançamento em categoria `group` misturaria o total próprio da categoria com a
 * soma das filhas — a mesma armadilha de plano de contas contábil de verdade.
 */
enum FinancialCategoryKind: string
{
    case GROUP = 'group';
    case ENTRY = 'entry';

    public function label(): string
    {
        return match ($this) {
            self::GROUP => 'Grupo',
            self::ENTRY => 'Categoria',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
