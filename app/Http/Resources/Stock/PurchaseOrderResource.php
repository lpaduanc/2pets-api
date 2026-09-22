<?php

namespace App\Http\Resources\Stock;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'legal_name' => $this->supplier->legal_name,
                'trade_name' => $this->supplier->trade_name,
            ]),
            'supplier_id' => $this->supplier_id,
            'expected_at' => $this->expected_at?->toDateString(),
            'notes' => $this->notes,
            'total' => (float) $this->total,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (PurchaseOrderItem $item): array => [
                'id' => $item->id,
                'product' => $item->product ? ['id' => $item->product->id, 'name' => $item->product->name, 'unit_of_sale' => $item->product->unit_of_sale] : null,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'received_quantity' => $item->received_quantity,
                'pending_quantity' => $item->pendingQuantity(),
                'unit_cost' => (float) $item->unit_cost,
                'total' => (float) $item->total,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
