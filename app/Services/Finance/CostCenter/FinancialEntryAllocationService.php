<?php

namespace App\Services\Finance\CostCenter;

use App\Models\FinancialEntry;
use Illuminate\Support\Facades\DB;

/**
 * Grava o rateio de um lançamento por centro de custo (PLANO 2.4). Sempre recalcula os valores
 * a partir do `net_amount` ATUAL (`CostCenterAllocator`), então Σ amounts = net_amount.
 *  - `replace()`: rateio explícito do lançamento (ou `[]` = "Não rateado" de propósito);
 *  - `applyCategoryDefault()`: o rateio padrão da categoria, para o lançamento que nasceu sem
 *    rateio — manual ou automático (venda, compra, fatura, antecipação, encargos do
 *    crediário…): todos passam pelo observer do `FinancialEntry`;
 *  - `reallocate()`: o valor líquido mudou (edição, desconto/multa/juros na baixa): mesmos
 *    percentuais, valores refeitos.
 */
final class FinancialEntryAllocationService
{
    public function __construct(private readonly CostCenterAllocator $allocator) {}

    /** @param  list<array{cost_center_id: int, percent: string|int|float}>  $allocations */
    public function replace(FinancialEntry $entry, array $allocations): void
    {
        DB::transaction(function () use ($entry, $allocations): void {
            $entry->allocations()->delete();

            if ($allocations === []) {
                return;
            }

            foreach ($this->allocator->allocate((string) $entry->net_amount, $allocations) as $line) {
                $entry->allocations()->create($line);
            }
        });
    }

    public function applyCategoryDefault(FinancialEntry $entry): void
    {
        $default = $entry->financial_category_id === null ? null : $entry->category()->value('default_allocation');
        $lines = is_string($default) ? json_decode($default, true) : $default;

        if (! is_array($lines) || $lines === [] || $entry->allocations()->exists()) {
            return;
        }

        $this->replace($entry, $lines);
    }

    public function reallocate(FinancialEntry $entry): void
    {
        $current = $entry->allocations()->get(['cost_center_id', 'percent'])
            ->map(fn ($line): array => ['cost_center_id' => (int) $line->cost_center_id, 'percent' => (string) $line->percent])->all();

        if ($current !== []) {
            $this->replace($entry, $current);
        }
    }
}
