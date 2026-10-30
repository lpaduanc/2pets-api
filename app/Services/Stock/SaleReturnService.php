<?php

namespace App\Services\Stock;

use App\Enums\RefundMethod;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Services\Commercial\ServiceCost\ServiceSupplyConsumer;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Devolução de venda — docs/gap-simplesvet/07. Estorna o estoque (`return_in`) e o dinheiro. O
 * valor de cada linha vem do `SaleReturnRefundCalculator`, o mesmo da prévia.
 *
 * Para onde vai o dinheiro (caixa, financeiro, conta corrente do cliente) é do
 * `SaleReturnRefundEffects`.
 *
 * Acréscimo de repasse da taxa do cartão (PLANO 1.1): `keep` nunca o devolve; `proportional`
 * devolve a parte dos itens devolvidos, somada ao `total` e registrada em `surcharge_refund`.
 */
final class SaleReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly ServiceSupplyConsumer $serviceSupplies,
        private readonly SaleReturnRefundCalculator $refunds,
        private readonly SaleReturnRefundEffects $refundEffects,
    ) {}

    /**
     * @param  array<int, array{sale_item_id: int, quantity: int|float|string}>  $items
     */
    public function create(Sale $sale, User $user, array $items, RefundMethod $refundMethod, string $reason): SaleReturn
    {
        return DB::transaction(function () use ($sale, $user, $items, $refundMethod, $reason): SaleReturn {
            $refund = $this->refunds->calculate($sale, $items, $refundMethod);
            $return = SaleReturn::create([
                'organization_id' => $sale->organization_id,
                'professional_id' => $sale->professional_id,
                'sale_id' => $sale->id,
                'reason' => $reason,
                'refund_method' => $refundMethod,
                'user_id' => $user->id,
            ]);

            foreach ($refund['lines'] as $line) {
                $this->returnLine($sale, $return, $user, $line);
            }

            // O acréscimo devolvido entra no total (é o que sai do caixa) e fica também à parte,
            // para a tela e o financeiro saberem quanto dele voltou.
            $return->update([
                'total' => $refund['total_refund']->toString(),
                'surcharge_refund' => $refund['surcharge_refund']->toString(),
            ]);

            $this->refundEffects->apply($sale, $return, $refundMethod, $refund['items_refund'], $refund['surcharge_refund'], $user);

            return $return->load('items.saleItem', 'sale', 'user');
        });
    }

    /**
     * Mesma conta e mesmas validações do `create`, sem gravar nada.
     *
     * @param  array<int, array{sale_item_id: int, quantity: int|float|string}>  $items
     * @return array{lines: list<array{item: SaleItem, quantity: Quantity, returned_before: Quantity, refund: Money, surcharge: Money}>, items_refund: Money, surcharge_refund: Money, total_refund: Money}
     */
    public function preview(Sale $sale, array $items, RefundMethod $refundMethod): array
    {
        return $this->refunds->calculate($sale, $items, $refundMethod);
    }

    public function returnedQuantity(int $saleItemId): Quantity
    {
        return $this->refunds->returnedQuantity($saleItemId);
    }

    /** @param  array{item: SaleItem, quantity: Quantity, returned_before: Quantity, refund: Money, surcharge: Money}  $line */
    private function returnLine(Sale $sale, SaleReturn $return, User $user, array $line): void
    {
        $saleItem = $line['item'];
        $quantity = $line['quantity'];
        // Lido ANTES de gravar a linha desta devolução: é o que já tinha voltado deste produto.
        $productBack = $saleItem->sellable instanceof Product
            ? SaleReturnItem::returnedForProduct($sale->id, $saleItem->sellable->getKey())
            : Quantity::zero();

        SaleReturnItem::create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $saleItem->id,
            'quantity' => $quantity->toString(),
            'unit_price' => $saleItem->netUnitPrice(),
            'total' => $line['refund']->toString(),
        ]);

        $notes = 'Devolução da venda '.($sale->number ?? $sale->id);

        if ($saleItem->movesStock() && $saleItem->sellable instanceof Product) {
            // Volta com o valor que saiu na venda (rateio acumulado por produto).
            $this->stock->in($saleItem->sellable, StockMovementType::RETURN_IN, $quantity, [
                'unit_cost' => $saleItem->unit_cost,
                'total_cost' => $this->stock->returningValue($saleItem->sellable, $sale, StockMovementType::SALE_OUT, $productBack, $quantity),
                'reference' => $return,
                'user' => $user,
                'notes' => $notes,
            ]);
        }

        // Serviço devolvido: os insumos da parte devolvida voltam (PLANO 1.3).
        $this->serviceSupplies->reverseForSaleItem($saleItem, $line['returned_before']->plus($quantity), ['user' => $user, 'notes' => $notes]);
    }
}
