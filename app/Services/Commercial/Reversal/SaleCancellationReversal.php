<?php

namespace App\Services\Commercial\Reversal;

use App\Models\CashRegister;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\SaleClientAccountEffects;
use App\Services\Finance\SaleRevenueReversalRecorder;

/**
 * Efeito financeiro do cancelamento da venda: estorna cada recebimento (`ReceiptReversalService`,
 * a unidade do PLANO 2.3), a receita lançada (`SaleRevenueReversalRecorder`) e o débito de venda a
 * prazo na conta corrente do cliente (`SaleClientAccountEffects`). Checa TODOS os
 * recebimentos antes de mexer em qualquer um — um recebível já pago pela adquirente recusa o
 * cancelamento inteiro sem deixar estorno pela metade.
 */
final class SaleCancellationReversal
{
    public function __construct(
        private readonly ReceiptReversalService $receipts,
        private readonly SaleRevenueReversalRecorder $revenue,
        private readonly SaleClientAccountEffects $clientAccounts,
    ) {}

    public function reverse(Sale $sale, User $user, ?CashRegister $register): void
    {
        $receipts = $sale->receipts()->with('paymentMethod')->orderBy('id')->get();

        $receipts->each(fn ($receipt) => $this->receipts->assertReversible($receipt));
        $receipts->each(fn ($receipt) => $this->receipts->reverse($sale, $receipt, $user, $register));

        $this->revenue->reverse($sale, $user);
        // Venda a prazo (total ou o resto no fiado): o débito do cliente volta a zero.
        $this->clientAccounts->reverseDebitForCancelledSale($sale, $user);
    }
}
