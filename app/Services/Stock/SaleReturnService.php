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

            $total = 0.0;
            $saleItems = $sale->items()->with('sellable')->get()->keyBy('id');

            foreach ($items as $entry) {
                $saleItem = $saleItems->get((int) $entry['sale_item_id']);
                abort_if($saleItem === null, 422, 'Item não pertence a esta venda.');

                $quantity = (int) $entry['quantity'];
                $available = (int) ceil((float) $saleItem->quantity) - $this->returnedQuantity($saleItem->id);
                abort_if($quantity < 1 || $quantity > $available, 422, sprintf(
                    'Quantidade inválida para "%s": %d disponível(is) para devolução.',
                    $saleItem->description,
                    max(0, $available)
                ));

                // Preço efetivo da linha (já com o desconto do item), não o de tabela.
                $unitPrice = round($saleItem->calculateTotal() / max((float) $saleItem->quantity, 1), 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $total += $lineTotal;

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $saleItem->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $lineTotal,
                ]);

                if ($saleItem->movesStock() && $saleItem->sellable instanceof Product) {
                    $this->stock->in($saleItem->sellable, StockMovementType::RETURN_IN, $quantity, [
                        'unit_cost' => (float) $saleItem->unit_cost,
                        'reference' => $return,
                        'user' => $user,
                        'notes' => 'Devolução da venda '.($sale->number ?? $sale->id),
                    ]);
                }
            }

            $return->update(['total' => round($total, 2)]);

            if ($refundMethod === RefundMethod::CASH) {
                $this->refundInCash($sale, $return, $user);
            }

            return $return->load('items.saleItem', 'sale', 'user');
        });
    }

    public function returnedQuantity(int $saleItemId): int
    {
        return (int) SaleReturnItem::query()->where('sale_item_id', $saleItemId)->sum('quantity');
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
