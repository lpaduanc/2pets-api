<?php

namespace App\Services\Stock;

use App\Enums\RefundMethod;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Services\Commercial\Card\CardSurchargePolicy;
use App\Support\Money\Money;
use App\Support\Money\Quantity;

/**
 * Quanto uma devolução reembolsa, item a item — a MESMA conta do salvar (`SaleReturnService`) e
 * da prévia (`POST sales/{sale}/returns/preview`), sem gravar nada. Validações também são as
 * mesmas: o que a prévia aceita, o salvar aceita.
 *
 * Por linha, o reembolso é o que o cliente PAGOU por ela (total da linha menos a parte dela no
 * desconto do cabeçalho, maior resto) pelo rateio acumulado (`SaleItem::refundFor`): devolver tudo,
 * em quantas vezes for, devolve exatamente o pago. O acréscimo de repasse da taxa do cartão (PLANO
 * 1.1) só volta na política `proportional`, pelo mesmo rateio acumulado sobre a parte da linha.
 */
final class SaleReturnRefundCalculator
{
    /**
     * @param  array<int, array{sale_item_id: int, quantity: int|float|string}>  $entries
     * @return array{lines: list<array{item: SaleItem, quantity: Quantity, returned_before: Quantity, refund: Money, surcharge: Money}>, items_refund: Money, surcharge_refund: Money, total_refund: Money}
     */
    public function calculate(Sale $sale, array $entries, RefundMethod $refundMethod): array
    {
        $this->assertReturnable($sale, $refundMethod);

        $saleItems = $sale->items()->with('sellable')->get()->keyBy('id');
        $paidPerItem = $sale->setRelation('items', $saleItems->values())->netItemTotals();
        $surchargePerItem = $this->surchargePerItem($sale, $paidPerItem);

        $lines = array_map(
            fn (array $entry): array => $this->line($saleItems->get((int) $entry['sale_item_id']), Quantity::of($entry['quantity']), $paidPerItem, $surchargePerItem),
            array_values($entries),
        );
        $itemsRefund = Money::sum(array_column($lines, 'refund'));
        $surchargeRefund = Money::sum(array_column($lines, 'surcharge'));

        return ['lines' => $lines, 'items_refund' => $itemsRefund, 'surcharge_refund' => $surchargeRefund, 'total_refund' => $itemsRefund->plus($surchargeRefund)];
    }

    public function returnedQuantity(int $saleItemId): Quantity
    {
        return Quantity::of(SaleReturnItem::query()->where('sale_item_id', $saleItemId)->sum('quantity'));
    }

    private function assertReturnable(Sale $sale, RefundMethod $refundMethod): void
    {
        abort_unless($sale->status === SaleStatus::PAID, 422, 'Só venda paga pode ter devolução.');
        abort_if($refundMethod === RefundMethod::STORE_CREDIT && $sale->client_id === null, 422, 'Venda sem cliente identificado não pode gerar crédito.');
    }

    /**
     * @param  array<int, Money>  $paidPerItem
     * @param  array<int, Money>  $surchargePerItem
     * @return array{item: SaleItem, quantity: Quantity, returned_before: Quantity, refund: Money, surcharge: Money}
     */
    private function line(?SaleItem $item, Quantity $quantity, array $paidPerItem, array $surchargePerItem): array
    {
        abort_if($item === null, 422, 'Item não pertence a esta venda.');

        $returned = $this->returnedQuantity($item->id);
        $this->assertQuantity($item, $quantity, Quantity::of($item->quantity)->minus($returned));

        return [
            'item' => $item,
            'quantity' => $quantity,
            'returned_before' => $returned,
            'refund' => $item->refundFor($returned, $quantity, $paidPerItem[$item->id]),
            'surcharge' => $item->refundFor($returned, $quantity, $surchargePerItem[$item->id]),
        ];
    }

    private function assertQuantity(SaleItem $item, Quantity $quantity, Quantity $available): void
    {
        abort_if(! $quantity->isPositive() || $quantity->isGreaterThan($available), 422, sprintf(
            'Quantidade inválida para "%s": %s disponível(is) para devolução.',
            $item->description,
            $available->max(0)->toBigDecimal()->strippedOfTrailingZeros()
        ));

        abort_if(
            $item->sellable instanceof Product && ! $item->sellable->acceptsQuantity($quantity),
            422,
            sprintf('"%s" é vendido em unidades inteiras.', $item->description)
        );
    }

    /**
     * Parte de cada linha no acréscimo, rateada pelo maior resto sobre o que o cliente pagou por
     * linha. Política lida AGORA (`CardSurchargePolicy`): em `keep` (padrão) toda parte é zero.
     *
     * @param  array<int, Money>  $paidPerItem
     * @return array<int, Money>
     */
    private function surchargePerItem(Sale $sale, array $paidPerItem): array
    {
        $surcharge = Money::of($sale->surcharge_amount ?? '0');

        if ($paidPerItem === [] || $surcharge->isZero() || ! CardSurchargePolicy::forSale($sale)->refundsProportionally()) {
            return array_map(fn (): Money => Money::zero(), $paidPerItem);
        }

        return $surcharge->allocate($paidPerItem);
    }
}
