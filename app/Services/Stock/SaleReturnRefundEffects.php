<?php

namespace App\Services\Stock;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethodKind;
use App\Enums\RefundMethod;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Commercial\CashRegisterService;
use App\Services\Commercial\ClientAccountService;
use App\Services\Finance\SaleReturnFinancialEntryRecorder;
use App\Support\Money\Money;

/**
 * Para onde vai o dinheiro de uma devolução, por forma de reembolso:
 *
 *  - `cash`: o dinheiro SAI da empresa. Movimento `refund` no caixa ABERTO da venda (sem caixa
 *    aberto, sai por fora, como no cancelamento) e, sempre, a saída no financeiro
 *    (`SaleReturnFinancialEntryRecorder`) com a conta e a forma do recebimento de onde volta.
 *  - `store_credit`: o valor devolvido (itens + acréscimo proporcional = `sale_returns.total`)
 *    vira saldo credor na conta corrente do cliente (`return_credit`, referência à devolução,
 *    idempotente). Nenhum dinheiro sai: sem caixa e sem despesa no financeiro. A receita da
 *    venda continua no DRE — e é certo, porque a clínica segue com o dinheiro; quando o cliente
 *    usar o crédito, o recebimento `account_credit` da venda nova consome o saldo
 *    (`ClientAccountService::consumeCredit`) sem entrar no caixa, e a receita da venda nova é
 *    lançada normalmente. O que ainda não existe é a linha de "créditos de cliente em aberto"
 *    como passivo no financeiro — o saldo vive só na conta corrente.
 *  - `none`: troca — nada de dinheiro.
 */
final class SaleReturnRefundEffects
{
    public function __construct(
        private readonly CashRegisterService $cashRegisters,
        private readonly ClientAccountService $clientAccounts,
        private readonly SaleReturnFinancialEntryRecorder $financialEntries,
    ) {}

    public function apply(Sale $sale, SaleReturn $return, RefundMethod $method, Money $itemsRefund, Money $surchargeRefund, User $user): void
    {
        match ($method) {
            RefundMethod::CASH => $this->refundInCash($sale, $return, $itemsRefund, $surchargeRefund, $user),
            RefundMethod::STORE_CREDIT => $this->creditTheClient($sale, $return, $user),
            RefundMethod::NONE => null,
        };
    }

    private function refundInCash(Sale $sale, SaleReturn $return, Money $itemsRefund, Money $surchargeRefund, User $user): void
    {
        $receipt = $this->refundReceipt($sale);
        $register = $sale->cashRegister;

        if ($register !== null && $register->isOpen() && Money::of($return->total)->isPositive()) {
            $this->cashRegisters->recordMovement(
                $register,
                CashMovementType::REFUND,
                (float) $return->total,
                'Devolução da venda '.($sale->number ?? $sale->id),
                $user,
                $receipt?->payment_method_id,
                $receipt?->account_id,
                $return,
            );
        }

        $this->financialEntries->recordCashRefund($return, $receipt, $itemsRefund, $surchargeRefund, $user);
    }

    private function creditTheClient(Sale $sale, SaleReturn $return, User $user): void
    {
        if (! Money::of($return->total)->isPositive()) {
            return;
        }

        $ownership = ['organization_id' => $sale->organization_id, 'professional_id' => $sale->professional_id];
        $this->clientAccounts->creditForReturn($sale->client, $ownership, (float) $return->total, $return->id, $user);
    }

    /**
     * Recebimento de onde o dinheiro volta: o primeiro em dinheiro, senão o primeiro da venda.
     * O MESMO para o caixa e para o financeiro — gaveta e lançamento nunca divergem de forma.
     */
    private function refundReceipt(Sale $sale): ?SaleReceipt
    {
        $receipts = $sale->receipts()->with('paymentMethod')->orderBy('id')->get();

        return $receipts->first(fn (SaleReceipt $receipt): bool => $receipt->paymentMethod?->kind === PaymentMethodKind::CASH)
            ?? $receipts->first();
    }
}
