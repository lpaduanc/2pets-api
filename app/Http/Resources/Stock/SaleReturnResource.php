<?php

namespace App\Http\Resources\Stock;

use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleReturn
 */
class SaleReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale' => $this->whenLoaded('sale', fn () => $this->sale ? ['id' => $this->sale->id, 'number' => $this->sale->number] : null),
            'sale_id' => $this->sale_id,
            'reason' => $this->reason,
            'refund_method' => $this->refund_method->value,
            'refund_method_label' => $this->refund_method->label(),
            'total' => (float) $this->total,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (SaleReturnItem $item): array => [
                'id' => $item->id,
                'sale_item_id' => $item->sale_item_id,
                'description' => $item->saleItem?->description,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
