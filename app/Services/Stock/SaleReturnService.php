<?php

namespace App\Services\Stock;

use App\Enums\CashMovementType;
use App\Enums\RefundMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Services\Commercial\CashRegisterService;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Devolução de venda — docs/gap-simplesvet/07. Estorna o estoque (`return_in`) e o dinheiro.
 *
 * Efeito financeiro por forma de reembolso:
 *  - `cash`: movimento `refund` no caixa ABERTO da venda (se não houver caixa aberto, a
 *    devolução fica registrada e o estorno de dinheiro sai por fora — mesmo comportamento do
 *    `SaleService::cancel`);
 *  - `store_credit`: crédito na conta corrente do cliente (doc 11). Esse livro ainda não
 *    existe; a devolução fica registrada com o valor e o doc 11 a consome quando entrar;
 *  - `none`: troca — só estoque.
 */
final class SaleReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CashRegisterService $cashRegisters,
    ) {}

    /**
     * @param  array<int, array{sale_item_id: int, quantity: int}>  $items
     */
    public function create(Sale $sale, User $user, array $items, RefundMethod $refundMethod, string $reason): SaleReturn
    {
        abort_unless($sale->status === SaleStatus::PAID, 422, 'Só venda paga pode ter devolução.');
        abort_if($refundMethod === RefundMethod::STORE_CREDIT && $sale->client_id === null, 422, 'Venda sem cliente identificado não pode gerar crédito.');

        return DB::transaction(function () use ($sale, $user, $items, $refundMethod, $reason): SaleReturn {
            $return = SaleReturn::create([
                'organization_id' => $sale->organization_id,
                'professional_id' => $sale->professional_id,
                'sale_id' => $sale->id,
                'reason' => $reason,
                'refund_method' => $refundMethod,
                'user_id' => $user->id,
            ]);

            $total = Money::zero();
            $saleItems = $sale->items()->with('sellable')->get()->keyBy('id');
            // O que o cliente PAGOU por linha: total da linha menos a parte dela no desconto do
            // cabeçalho (rateio pelo maior resto). Devolver pelo total da linha devolveria o
            // desconto junto — 10% sobre R$ 100 pagos R$ 90 e devolvidos R$ 100.
            $paidPerItem = $sale->setRelation('items', $saleItems->values())->netItemTotals();

            foreach ($items as $entry) {
                $saleItem = $saleItems->get((int) $entry['sale_item_id']);
                abort_if($saleItem === null, 422, 'Item não pertence a esta venda.');

                $quantity = Quantity::of($entry['quantity']);
                $returned = $this->returnedQuantity($saleItem->id);
                $available = Quantity::of($saleItem->quantity)->minus($returned);
                abort_if(! $quantity->isPositive() || $quantity->isGreaterThan($available), 422, sprintf(
                    'Quantidade inválida para "%s": %s disponível(is) para devolução.',
                    $saleItem->description,
                    $available->max(0)->toBigDecimal()->strippedOfTrailingZeros()
                ));

                abort_if(
                    $saleItem->sellable instanceof Product && ! $saleItem->sellable->acceptsQuantity($quantity),
                    422,
                    sprintf('"%s" é vendido em unidades inteiras.', $saleItem->description)
                );

                // Valor pelo rateio acumulado da linha (já com o desconto do item), não o de tabela.
                $lineTotal = $saleItem->refundFor($returned, $quantity, $paidPerItem[$saleItem->id]);
                $total = $total->plus($lineTotal);

                $productBack = $saleItem->sellable instanceof Product
                    ? SaleReturnItem::returnedForProduct($sale->id, $saleItem->sellable->getKey())
                    : Quantity::zero();

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $saleItem->id,
                    'quantity' => $quantity->toString(),
                    'unit_price' => $saleItem->netUnitPrice(),
                    'total' => $lineTotal->toString(),
                ]);

                if ($saleItem->movesStock() && $saleItem->sellable instanceof Product) {
                    // Volta com o valor que saiu na venda (rateio acumulado por produto).
                    $this->stock->in($saleItem->sellable, StockMovementType::RETURN_IN, $quantity, [
                        'unit_cost' => $saleItem->unit_cost,
                        'total_cost' => $this->stock->returningValue($saleItem->sellable, $sale, StockMovementType::SALE_OUT, $productBack, $quantity),
                        'reference' => $return,
                        'user' => $user,
                        'notes' => 'Devolução da venda '.($sale->number ?? $sale->id),
                    ]);
                }
            }

            $return->update(['total' => $total->toString()]);

            if ($refundMethod === RefundMethod::CASH) {
                $this->refundInCash($sale, $return, $user);
            }

            return $return->load('items.saleItem', 'sale', 'user');
        });
    }

    public function returnedQuantity(int $saleItemId): Quantity
    {
        return Quantity::of(SaleReturnItem::query()->where('sale_item_id', $saleItemId)->sum('quantity'));
    }

    private function refundInCash(Sale $sale, SaleReturn $return, User $user): void
    {
        $register = $sale->cashRegister;

        if ($register === null || ! $register->isOpen() || (float) $return->total <= 0) {
            return;
        }

        $receipt = $sale->receipts()->first();

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
}
