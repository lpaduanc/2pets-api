<?php

namespace App\Observers;

use App\Models\FinancialEntry;
use App\Services\Finance\CostCenter\FinancialEntryAllocationService;

/**
 * Rateio por centro de custo de todo lançamento (PLANO 2.4), qualquer que seja a porta que o
 * criou: nasceu sem rateio explícito → rateio padrão da categoria (se houver; senão fica "Não
 * rateado"); valor líquido mudou → mesmos percentuais, valores refeitos. Quem grava rateio
 * explícito liga `skipDefaultAllocation` antes de criar.
 */
final class FinancialEntryAllocationObserver
{
    public function __construct(private readonly FinancialEntryAllocationService $allocations) {}

    public function created(FinancialEntry $entry): void
    {
        if (! $entry->skipDefaultAllocation) {
            $this->allocations->applyCategoryDefault($entry);
        }
    }

    public function updated(FinancialEntry $entry): void
    {
        if ($entry->wasChanged('net_amount')) {
            $this->allocations->reallocate($entry);
        }
    }
}
