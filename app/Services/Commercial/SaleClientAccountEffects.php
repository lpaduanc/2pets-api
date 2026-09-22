<?php

namespace App\Services\Commercial;

use App\Enums\ClientAccountEntryType;
use App\Enums\SaleStatus;
use App\Models\ClientAccountEntry;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;

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
     * Venda que ficou `unpaid` (fiado) pela primeira vez: débito pelo valor em aberto. Recebe
     * mais de uma vez a mesma venda continuando `unpaid` (pagamentos parciais sucessivos) não
     * duplica o débito — a reconciliação de pagamentos adicionais é manual, via
     * `POST clients/{client}/account-entries` (fora deste fluxo).
     */
    public function debitIfSaleWentUnpaid(Sale $sale, User $actor): void
    {
        if ($sale->status !== SaleStatus::UNPAID || $sale->client_id === null || $this->alreadyDebited($sale)) {
            return;
        }

        $this->accounts->debitForUnpaidSale($sale->client, $this->ownershipOf($sale), $sale->amountDue(), $sale->id, $actor);
    }

    /** Cancelamento de venda cujo recebimento era `account_credit`: devolve o crédito consumido. */
    public function refundForCancelledReceipt(Sale $sale, SaleReceipt $receipt, User $actor): void
    {
        if (! $receipt->paymentMethod?->kind->isDeferredToClientAccount() || $sale->client_id === null) {
            return;
        }

        $this->accounts->refundConsumedCredit($sale->client, $this->ownershipOf($sale), (float) $receipt->amount, $sale->id, $actor);
    }

    private function alreadyDebited(Sale $sale): bool
    {
        return ClientAccountEntry::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('type', ClientAccountEntryType::SALE_DEBIT->value)
            ->exists();
    }

    /** @return array{organization_id: int|null, professional_id: int} */
    private function ownershipOf(Sale $sale): array
    {
        return ['organization_id' => $sale->organization_id, 'professional_id' => $sale->professional_id];
    }
}
