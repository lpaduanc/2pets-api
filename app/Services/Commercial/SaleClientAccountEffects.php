<?php

namespace App\Services\Commercial;

use App\Enums\SaleStatus;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Efeito da venda sobre a conta corrente do cliente — contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md ("dois pontos de integração" +
 * "venda que fecha como unpaid"). Extraído do `SaleService` para não empurrar seu construtor
 * (já em 6 dependências) para uma 7ª, e para manter a regra de conta corrente num só lugar
 * fora do fluxo de caixa/estoque da venda.
 *
 * Sale sem `client_id` (consumidor não identificado) nunca toca a conta corrente — o balcão
 * continua funcionando exatamente como antes para venda anônima.
 */
final class SaleClientAccountEffects
{
    public function __construct(private readonly ClientAccountService $accounts) {}

    /** Recebimento com forma `account_credit`: consome o saldo credor do cliente. */
    public function consumeCreditForReceipt(Sale $sale, PaymentMethod $method, float $amount, User $actor): void
    {
        if (! $method->kind->isDeferredToClientAccount() || $sale->client_id === null) {
            return;
        }

        $this->accounts->consumeCredit($sale->client, $this->ownershipOf($sale), $amount, $sale->id, $actor);
    }

    /**
     * Venda que ficou `unpaid` (fiado) sem débito ATIVO na conta: débito pelo valor em aberto.
     * Recebe mais de uma vez a mesma venda continuando `unpaid` (pagamentos parciais sucessivos)
     * não duplica o débito — a reconciliação de pagamentos adicionais é manual, via
     * `POST clients/{client}/account-entries` (fora deste fluxo). "Ativo" = já descontados os
     * ajustes de estorno de recebimento (PLANO 2.3): venda que voltou a `open` com débito zerado
     * e ficou `unpaid` de novo é debitada de novo, uma vez.
     */
    public function debitIfSaleWentUnpaid(Sale $sale, User $actor): void
    {
        if ($sale->status !== SaleStatus::UNPAID || $sale->client_id === null || $this->accounts->saleDebitBalance($sale->id)->isPositive()) {
            return;
        }

        $this->accounts->debitForUnpaidSale($sale->client, $this->ownershipOf($sale), $sale->amountDue(), $sale->id, $actor);
    }

    /** Cancelamento de venda a prazo (fiado): estorna o débito — ver `ClientAccountService::reverseSaleDebit`. */
    public function reverseDebitForCancelledSale(Sale $sale, User $actor): void
    {
        if ($sale->client_id === null) {
            return;
        }

        $this->accounts->reverseSaleDebit($sale->client, $this->ownershipOf($sale), $sale->id, $actor);
    }

    /** Cancelamento de venda cujo recebimento era `account_credit`: devolve o crédito consumido. */
    public function refundForCancelledReceipt(Sale $sale, SaleReceipt $receipt, User $actor): void
    {
        if (! $receipt->paymentMethod?->kind->isDeferredToClientAccount() || $sale->client_id === null) {
            return;
        }

        $this->accounts->refundConsumedCredit($sale->client, $this->ownershipOf($sale), (float) $receipt->amount, $sale->id, $actor);
    }

    /**
     * Recebimento na venda que JÁ tem fiado ativo na conta: o débito vai para o saldo devido (0 se
     * a venda quitou), com ajuste referenciando o recebimento. Pagamento feito pela conta corrente
     * (crédito manual) quita a venda com um recebimento `account_credit`, que consome esse crédito
     * — o consumo (débito) e este ajuste (crédito) se anulam no saldo, sem abater em dobro.
     */
    public function settleDebitWithReceipt(Sale $sale, SaleReceipt $receipt, User $actor): void
    {
        if ($sale->client_id === null || ! $this->accounts->saleDebitBalance($sale->id)->isPositive()) {
            return;
        }

        $target = $sale->status === SaleStatus::PAID ? Money::zero() : $sale->amountDueMoney();
        $this->accounts->adjustSaleDebitTo($sale->client, $this->ownershipOf($sale), $target, $sale->id, $receipt->id, $actor, ClientAccountService::RECEIPT_SETTLEMENT_REFERENCE);
    }

    /**
     * Depois do estorno de um recebimento (PLANO 2.3), o fiado da venda reflete o estado dela:
     * `unpaid` (ainda com recebimento valendo) → débito = saldo devido; `open` (nenhum
     * recebimento valendo) → débito 0, deixa de ser fiado; `paid` → nada muda. Por lançamento de
     * ajuste, idempotente.
     */
    public function reconcileAfterReceiptCancellation(Sale $sale, SaleReceipt $receipt, User $actor): void
    {
        if ($sale->client_id === null || $sale->status === SaleStatus::PAID) {
            return;
        }

        $target = $sale->status === SaleStatus::UNPAID ? $sale->amountDueMoney() : Money::zero();
        $this->accounts->adjustSaleDebitTo($sale->client, $this->ownershipOf($sale), $target, $sale->id, $receipt->id, $actor);
    }

    /** @return array{organization_id: int|null, professional_id: int} */
    private function ownershipOf(Sale $sale): array
    {
        return ['organization_id' => $sale->organization_id, 'professional_id' => $sale->professional_id];
    }
}
