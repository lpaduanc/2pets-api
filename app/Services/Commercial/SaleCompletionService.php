<?php

namespace App\Services\Commercial;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\Pricing\SalePricingGuard;
use App\Services\Finance\ClientCredit\ClientCreditPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Concluir venda" do PDV (`POST sales/{id}/complete`). É AQUI que o fiado é julgado, não a cada
 * recebimento — dinheiro + cartão são duas chamadas, e a venda em andamento com pagamento parcial
 * não é venda a prazo:
 *  - já paga: nada a fazer (idempotente);
 *  - quitada sem estar `paid` (venda de R$ 0,00 — só brinde/cortesia): fecha como o `finalize`;
 *  - com saldo devido: vira venda a prazo — exige cliente identificado, a trava do doc 11
 *    (`allow_credit_sale` e limite, 422 com a mensagem da conta corrente), o `sale_debit` do saldo
 *    e o status `unpaid`, numa transação só.
 * As regras de preço da finalização (teto de desconto, abaixo do custo) valem nos dois casos.
 */
final class SaleCompletionService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly SalePricingGuard $pricingRules,
        private readonly SaleClientAccountEffects $clientAccounts,
        private readonly ClientCreditPlanService $creditPlans,
    ) {}

    /**
     * `$creditInstallments`/`$firstDueDate` (PLANO 2.2): concluída a prazo, o saldo vira crediário
     * parcelado. Sem parcelas, é o fiado de sempre (um débito só na conta corrente).
     */
    public function complete(Sale $sale, User $user, ?int $creditInstallments = null, ?string $firstDueDate = null): Sale
    {
        abort_if($sale->isQuote(), 422, 'Orçamento não é concluído no PDV. Converta em venda primeiro.');
        abort_if($sale->status === SaleStatus::CANCELLED, 422, 'Venda cancelada não pode ser concluída.');

        if ($sale->status === SaleStatus::PAID || $sale->status === SaleStatus::UNPAID) {
            return $sale->fresh(Sale::RESOURCE_RELATIONS);
        }

        if ($sale->isFullyPaid()) {
            return $this->sales->finalizeWithoutPayment($sale, $user);
        }

        return $this->completeOnCredit($sale, $user, $creditInstallments, $firstDueDate);
    }

    private function completeOnCredit(Sale $sale, User $user, ?int $creditInstallments, ?string $firstDueDate): Sale
    {
        abort_if(! $sale->items()->exists(), 422, 'Adicione ao menos um item antes de receber.');

        if ($sale->client_id === null) {
            throw ValidationException::withMessages(['client_id' => 'Para concluir com saldo em aberto (fiado), identifique o cliente da venda.']);
        }

        $this->pricingRules->assertReadyToReceive($sale);

        return DB::transaction(function () use ($sale, $user, $creditInstallments, $firstDueDate): Sale {
            $sale->status = SaleStatus::UNPAID;
            $sale->save();
            // Trava do doc 11 (fiado liberado e limite): 422 aqui desfaz a conclusão inteira.
            $this->clientAccounts->debitIfSaleWentUnpaid($sale, $user);

            if ($creditInstallments !== null) {
                $this->creditPlans->createForSale($sale, $user, $creditInstallments, $firstDueDate ?? \App\Support\BusinessClock::today()->addMonthNoOverflow()->toDateString());
            }

            return $sale->fresh(Sale::RESOURCE_RELATIONS);
        });
    }
}
