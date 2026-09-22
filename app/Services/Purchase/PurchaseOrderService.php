<?php

namespace App\Services\Purchase;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Facades\DB;

/**
 * Pedido de compra — docs/gap-simplesvet/06 (`/v3/comercial/pedidos-compra`): o ciclo
 * pedido → recebimento, anterior à entrada de nota.
 *
 * O pedido NUNCA mexe em estoque. Receber mercadoria gera uma COMPRA em rascunho com os
 * itens recebidos; é a efetivação dessa compra (`PurchaseService::receive`) que dá entrada no
 * estoque e atualiza o status do pedido (parcial/recebido). Assim a conferência da nota
 * acontece num lugar só, com ou sem pedido.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly OwnerSequence $sequence,
        private readonly PurchaseService $purchases,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $data): PurchaseOrder {
            $ownership = $this->scope->ownershipFor($user);

            $order = PurchaseOrder::create([
                'code' => $this->sequence->next(PurchaseOrder::class, $ownership),
                'supplier_id' => $this->supplierId($user, $data['supplier_id']),
                'status' => PurchaseOrderStatus::DRAFT,
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ] + $ownership);

            $this->syncItems($order, $user, $data['items']);

            return $order->fresh(PurchaseOrder::RESOURCE_RELATIONS);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $order, User $user, array $data): PurchaseOrder
    {
        abort_unless($order->status === PurchaseOrderStatus::DRAFT, 422, 'Só pedido em rascunho pode ser editado.');

        return DB::transaction(function () use ($order, $user, $data): PurchaseOrder {
            $order->update([
                'supplier_id' => $this->supplierId($user, $data['supplier_id']),
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($order, $user, $data['items']);

            return $order->fresh(PurchaseOrder::RESOURCE_RELATIONS);
        });
    }

    public function send(PurchaseOrder $order): PurchaseOrder
    {
        abort_unless($order->status === PurchaseOrderStatus::DRAFT, 422, 'Pedido já enviado.');

        $order->update(['status' => PurchaseOrderStatus::SENT, 'sent_at' => now()]);

        return $order->fresh(PurchaseOrder::RESOURCE_RELATIONS);
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        abort_if(
            in_array($order->status, [PurchaseOrderStatus::RECEIVED, PurchaseOrderStatus::CANCELLED], true),
            422,
            'Pedido já recebido ou cancelado.'
        );

        $order->update(['status' => PurchaseOrderStatus::CANCELLED, 'cancelled_at' => now()]);

        return $order->fresh(PurchaseOrder::RESOURCE_RELATIONS);
    }

    public function delete(PurchaseOrder $order): void
    {
        abort_unless($order->status === PurchaseOrderStatus::DRAFT, 422, 'Só pedido em rascunho pode ser excluído.');

        $order->delete();
    }

    /**
     * Mercadoria chegou: vira uma compra em rascunho com as quantidades recebidas.
     *
     * @param  list<array{purchase_order_item_id: int, quantity: int}>  $received
     */
    public function receive(PurchaseOrder $order, User $user, array $received): Purchase
    {
        abort_unless($order->status->isReceivable(), 422, 'Pedido não aceita mais recebimento.');

        $orderItems = $order->items()->get()->keyBy('id');
        $items = [];

        foreach ($received as $entry) {
            $orderItem = $orderItems->get((int) $entry['purchase_order_item_id']);
            abort_if($orderItem === null, 422, 'Item não pertence a este pedido.');

            $quantity = (int) $entry['quantity'];

            if ($quantity < 1) {
                continue;
            }

            $items[] = [
                'product_id' => $orderItem->product_id,
                'purchase_order_item_id' => $orderItem->id,
                'quantity' => $quantity,
                'unit_cost' => (float) $orderItem->unit_cost,
            ];
        }

        abort_if($items === [], 422, 'Informe a quantidade recebida de ao menos um item.');

        return $this->purchases->create($user, [
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'entered_at' => now(),
            'items' => $items,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(PurchaseOrder $order, User $user, array $items): void
    {
        $order->items()->delete();
        $total = 0.0;

        foreach ($items as $data) {
            $product = $this->scope->scopeQuery(Product::query(), $user)->findOrFail((int) $data['product_id']);
            $quantity = (int) $data['quantity'];
            $unitCost = round((float) ($data['unit_cost'] ?? $product->last_cost), 4);
            $lineTotal = round($quantity * $unitCost, 2);
            $total += $lineTotal;

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total' => $lineTotal,
            ]);
        }

        $order->update(['total' => round($total, 2)]);
    }

    private function supplierId(User $user, mixed $supplierId): int
    {
        return $this->scope->scopeQuery(Supplier::query(), $user)->findOrFail((int) $supplierId)->id;
    }
}
