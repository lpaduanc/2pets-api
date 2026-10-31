<?php

namespace App\Listeners;

use App\Contracts\AffectsSaleProfitability;
use App\Models\Sale;
use App\Services\Commercial\Profitability\SaleProfitabilityService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Recalcula a rentabilidade da venda (PLANO 1.5) depois que a transação do fato confirma —
 * em fila (`QUEUE_CONNECTION`; com `sync`, na hora). Sem worker rodando, o job espera na fila e
 * o `GET sales/{id}/profitability` calcula a venda paga que ainda não tem linhas. Recalcular é
 * idempotente, então evento repetido não estraga nada.
 */
class RecomputeSaleProfitability implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(private readonly SaleProfitabilityService $profitability) {}

    public function handle(AffectsSaleProfitability $event): void
    {
        $sale = Sale::find($event->saleId());

        if ($sale !== null) {
            $this->profitability->recompute($sale);
        }
    }
}
