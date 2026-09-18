<?php

namespace App\Services\Commercial;

use App\Contracts\Sellable;
use App\Enums\CashMovementType;
use App\Enums\DiscountType;
use App\Enums\FiscalOperation;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\Commercial\PriceOverrideNotAllowedException;
use App\Exceptions\Commercial\SaleNotEditableException;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReceipt;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Venda de balcão — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Único lugar que cria venda, adiciona item, aplica desconto e registra recebimento. As regras
 * que este service concentra e que não podem vazar para o controller:
 *
 *  - venda só entra em caixa ABERTO (delegado a `CashRegisterService`, que é o portão);
 *  - orçamento (`kind = quote`) não movimenta caixa nem estoque — só a conversão movimenta;
 *  - item com `allow_price_override = false` recusa preço diferente do cadastrado;
 *  - recebimento grava taxa e previsão de depósito CONGELADAS a partir da forma de pagamento.
 */
final class SaleService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CashRegisterService $cashRegisters,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): Sale
    {
        $kind = SaleKind::from($attributes['kind'] ?? SaleKind::SALE->value);
        $ownership = $this->scope->ownershipFor($user);

        return DB::transaction(function () use ($user, $attributes, $kind, $ownership): Sale {
            // Orçamento nunca prende caixa: ele pode ser feito hoje e virar venda semana que
            // vem, com outro operador e outro caixa (doc 24).
            $cashRegisterId = $kind->movesMoney()
                ? ($attributes['cash_register_id'] ?? $this->cashRegisters->currentFor($user)?->id)
                : null;

            return Sale::create([
                'number' => $this->nextNumber($ownership),
                'client_id' => $attributes['client_id'] ?? null,
                'pet_id' => $attributes['pet_id'] ?? null,
                'cash_register_id' => $cashRegisterId,
                'kind' => $kind,
                'fiscal_operation' => FiscalOperation::from(
                    $attributes['fiscal_operation'] ?? FiscalOperation::IN_PERSON_CONSUMER->value
                ),
                'status' => SaleStatus::from($attributes['status'] ?? SaleStatus::OPEN->value),
                'discount_type' => DiscountType::NONE,
                'printed_notes' => $attributes['printed_notes'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'valid_until' => $attributes['valid_until'] ?? null,
                'created_by' => $user->id,
                'sold_at' => $kind->movesMoney() ? ($attributes['sold_at'] ?? now()) : null,
            ] + $ownership);
        });
    }

    /**
     * Adiciona uma linha. O preço informado só vence o cadastrado quando o item permite —
     * caso contrário, 422 (critério de aceite do doc 08).
     *
     * `unit_cost` e `commission_percent` são congelados aqui: são a base do doc 09, e a regra
     * de comissão precisa do custo e do percentual QUE VALIAM na hora da venda.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(Sale $sale, array $attributes): SaleItem
    {
        $this->assertEditable($sale);

        $sellable = $this->resolveSellable($attributes['sellable_type'], (int) $attributes['sellable_id']);
        $quantity = round((float) ($attributes['quantity'] ?? 1), 3);
        $unitPrice = $this->resolveUnitPrice($sellable, $attributes);

        return DB::transaction(function () use ($sale, $sellable, $attributes, $quantity, $unitPrice): SaleItem {
            $item = new SaleItem([
                'sale_id' => $sale->id,
                'sellable_type' => $sellable::class,
                'sellable_id' => $sellable->getKey(),
                'description' => $attributes['description'] ?? $sellable->sellableName(),
                'staff_id' => $attributes['staff_id'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $sellable->unitCost(),
                'discount' => round((float) ($attributes['discount'] ?? 0), 2),
                'commission_percent' => $sellable->commissionPercent(),
            ]);

            $item->total = $item->calculateTotal();
            $item->save();

            $this->refreshTotals($sale);

            return $item->load('staff.user');
        });
    }

    public function removeItem(Sale $sale, int $itemId): void
    {
        $this->assertEditable($sale);

        DB::transaction(function () use ($sale, $itemId): void {
            $sale->items()->whereKey($itemId)->delete();
            $this->refreshTotals($sale);
        });
    }

    public function applyDiscount(Sale $sale, DiscountType $type, float $value): Sale
    {
        $this->assertEditable($sale);

        $sale->discount_type = $type;
        $sale->discount_value = $type === DiscountType::NONE ? 0 : round($value, 2);
        $this->refreshTotals($sale);

        return $sale->fresh(Sale::RESOURCE_RELATIONS);
    }

    /**
     * Registra UM recebimento. Chamado uma vez por forma de pagamento — "dinheiro + cartão"
     * são duas chamadas, dois `sale_receipts` e dois movimentos de caixa (critério de aceite).
     *
     * Quando o total recebido cobre a venda, ela vira `paid`, o estoque baixa e a data de
     * venda é carimbada. Enquanto não cobrir, fica `unpaid` — é o pagamento parcial, que o
     * balcão usa o dia inteiro.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerReceipt(Sale $sale, User $user, array $attributes): SaleReceipt
    {
        abort_if($sale->isQuote(), 422, 'Orçamento não recebe pagamento. Converta em venda primeiro.');
        abort_if($sale->status === SaleStatus::CANCELLED, 422, 'Venda cancelada não recebe pagamento.');

        $method = $this->scope->scopeQuery(PaymentMethod::query(), $user)
            ->active()
            ->findOrFail($attributes['payment_method_id']);

        $amount = round((float) $attributes['amount'], 2);
        abort_if($amount <= 0, 422, 'O valor do recebimento deve ser maior que zero.');

        return DB::transaction(function () use ($sale, $user, $attributes, $method, $amount): SaleReceipt {
            $receivedAt = isset($attributes['received_at'])
                ? \Carbon\CarbonImmutable::parse($attributes['received_at'])
                : \Carbon\CarbonImmutable::now();

            $fee = $method->feeFor($amount);

            $receipt = $sale->receipts()->create([
                'payment_method_id' => $method->id,
                'account_id' => $attributes['account_id'] ?? $method->default_account_id,
                'amount' => $amount,
                'installments' => (int) ($attributes['installments'] ?? 1),
                'received_at' => $receivedAt,
                'operator_fee' => $fee,
                'net_amount' => round($amount - $fee, 2),
                // Só quem passa por adquirente espera depósito. Dinheiro na gaveta não tem
                // "previsão de liquidação", e preencher com a data de hoje faria a tela de
                // conciliação listar toda venda em dinheiro como pendente.
                'expected_settlement_date' => $method->kind->settlesThroughAcquirer()
                    ? $method->expectedSettlementDate($receivedAt)->toDateString()
                    : null,
                'received_by' => $user->id,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->mirrorReceiptInCashRegister($sale, $receipt, $method, $user);

            $sale->paid_amount = round((float) $sale->receipts()->sum('amount'), 2);
            $sale->status = $sale->isFullyPaid() ? SaleStatus::PAID : SaleStatus::UNPAID;

            if ($sale->status === SaleStatus::PAID) {
                $sale->sold_at ??= now();
                $sale->save();
                $this->deductStock($sale, $user);
            } else {
                $sale->save();
            }

            return $receipt->load('paymentMethod');
        });
    }

    /**
     * Orçamento → venda. Cria uma venda NOVA copiando os itens, em vez de mudar o `kind` no
     * lugar: o orçamento tem que continuar existindo como peça (doc 24), e o cliente que
     * recebeu o PDF precisa poder conferir o documento original depois.
     */
    public function convertQuoteToSale(Sale $quote, User $user): Sale
    {
        abort_unless($quote->isQuote(), 422, 'Esta venda não é um orçamento.');
        abort_if($quote->converted_to_sale_id !== null, 422, 'Este orçamento já foi convertido em venda.');

        return DB::transaction(function () use ($quote, $user): Sale {
            $sale = $this->create($user, [
                'kind' => SaleKind::SALE->value,
                'client_id' => $quote->client_id,
                'pet_id' => $quote->pet_id,
                'fiscal_operation' => $quote->fiscal_operation->value,
                'printed_notes' => $quote->printed_notes,
                'notes' => $quote->notes,
            ]);

            foreach ($quote->items as $item) {
                $sale->items()->create([
                    'sellable_type' => $item->sellable_type,
                    'sellable_id' => $item->sellable_id,
                    'description' => $item->description,
                    'staff_id' => $item->staff_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'unit_cost' => $item->unit_cost,
                    'discount' => $item->discount,
                    'total' => $item->total,
                    'commission_percent' => $item->commission_percent,
                ]);
            }

            $sale->discount_type = $quote->discount_type;
            $sale->discount_value = $quote->discount_value;
            $this->refreshTotals($sale);

            $quote->update(['converted_to_sale_id' => $sale->id]);

            return $sale->fresh(Sale::RESOURCE_RELATIONS);
        });
    }

    /**
     * Cancela. Não apaga nada: estorna o que entrou no caixa com movimentos `refund` e devolve
     * o estoque. Venda é documento — some do faturamento, não do histórico.
     */
    public function cancel(Sale $sale, User $user, string $reason): Sale
    {
        abort_if($sale->status === SaleStatus::CANCELLED, 422, 'Venda já cancelada.');

        return DB::transaction(function () use ($sale, $user, $reason): Sale {
            $register = $sale->cashRegister;

            if ($register !== null && $register->isOpen()) {
                foreach ($sale->receipts as $receipt) {
                    $this->cashRegisters->recordMovement(
                        $register,
                        CashMovementType::REFUND,
                        (float) $receipt->amount,
                        'Estorno da venda '.($sale->number ?? $sale->id),
                        $user,
                        $receipt->payment_method_id,
                        $receipt->account_id,
                        $sale,
                    );
                }
            }

            if ($sale->status === SaleStatus::PAID) {
                $this->restoreStock($sale, $user);
            }

            $sale->update([
                'status' => SaleStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $sale->fresh(Sale::RESOURCE_RELATIONS);
        });
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function assertEditable(Sale $sale): void
    {
        if (! $sale->status->isEditable()) {
            throw new SaleNotEditableException($sale);
        }
    }

    private function refreshTotals(Sale $sale): void
    {
        $sale->recalculateTotals();
        $sale->save();
    }

    /**
     * `sellable_type` chega como "product"/"service" (o app não conhece namespace PHP) e vira
     * a classe aqui. Um `match` explícito, e não `class_exists($input)`: aceitar nome de
     * classe vindo do cliente seria deixar o usuário escolher qual model instanciar.
     */
    private function resolveSellable(string $type, int $id): Sellable
    {
        return match ($type) {
            'product', Product::class => Product::findOrFail($id),
            'service', Service::class => Service::findOrFail($id),
            default => abort(422, 'Tipo de item inválido. Use "product" ou "service".'),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveUnitPrice(Sellable $sellable, array $attributes): float
    {
        $cataloguePrice = $sellable->sellableUnitPrice();

        if (! array_key_exists('unit_price', $attributes) || $attributes['unit_price'] === null) {
            return $cataloguePrice;
        }

        $requested = round((float) $attributes['unit_price'], 2);

        // Um centavo de tolerância: o front manda o preço que leu, e 89.90 pode voltar como
        // 89.899999 depois de um `toFixed` mal arredondado. Recusar isso seria recusar o
        // preço do próprio cadastro.
        if (abs($requested - $cataloguePrice) <= 0.01) {
            return $cataloguePrice;
        }

        if (! $sellable->allowsPriceOverride()) {
            throw new PriceOverrideNotAllowedException($sellable, $requested);
        }

        return $requested;
    }

    /**
     * Espelha o recebimento no caixa. Só quando há caixa ABERTO vinculado: venda a prazo
     * recebida amanhã não deve entrar no caixa de hoje, e recebimento em conta corrente do
     * cliente (doc 11) não entra em caixa nenhum — é dívida, não dinheiro.
     */
    private function mirrorReceiptInCashRegister(Sale $sale, SaleReceipt $receipt, PaymentMethod $method, User $user): void
    {
        if ($method->kind->isDeferredToClientAccount()) {
            return;
        }

        $register = $sale->cashRegister;

        if ($register === null || ! $register->isOpen()) {
            return;
        }

        $this->cashRegisters->recordMovement(
            $register,
            CashMovementType::SALE_RECEIPT,
            (float) $receipt->amount,
            'Recebimento da venda '.($sale->number ?? $sale->id),
            $user,
            $method->id,
            $receipt->account_id,
            $sale,
            $receipt->received_at,
        );
    }

    /**
     * Baixa de estoque na venda paga. O contrato completo é do doc 07 (`stock_movements`,
     * lote, devolução); aqui fazemos o mínimo honesto: decrementa `products.stock_quantity`
     * do que controla estoque. Quando o doc 07 entrar, esta chamada vira `StockService::out()`
     * e o resto continua igual.
     */
    private function deductStock(Sale $sale, User $user): void
    {
        foreach ($sale->items()->with('sellable')->get() as $item) {
            if (! $item->movesStock()) {
                continue;
            }

            $product = $item->sellable;

            // `lockForUpdate` dentro da transação: duas vendas simultâneas do mesmo produto
            // não podem ler o mesmo saldo e gravar o mesmo decremento.
            Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->decrement('stock_quantity', (int) ceil((float) $item->quantity));
        }
    }

    private function restoreStock(Sale $sale, User $user): void
    {
        foreach ($sale->items()->with('sellable')->get() as $item) {
            if (! $item->movesStock()) {
                continue;
            }

            Product::query()
                ->whereKey($item->sellable->getKey())
                ->lockForUpdate()
                ->increment('stock_quantity', (int) ceil((float) $item->quantity));
        }
    }

    /**
     * Próximo número visível da venda, por dono. `MAX + 1` dentro da transação da criação;
     * o índice único parcial (`sales_org_number_unique`) é o que garante a unicidade real se
     * duas vendas nascerem no mesmo milissegundo.
     *
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    private function nextNumber(array $ownership): int
    {
        $query = Sale::withTrashed();

        if ($ownership['organization_id'] !== null) {
            $query->where('organization_id', $ownership['organization_id']);
        } else {
            $query->where('professional_id', $ownership['professional_id'])->whereNull('organization_id');
        }

        return ((int) $query->lockForUpdate()->max('number')) + 1;
    }
}
