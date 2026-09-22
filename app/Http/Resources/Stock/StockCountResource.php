<?php

namespace App\Http\Resources\Stock;

use App\Models\StockCount;
use App\Models\StockCountItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockCount
 */
class StockCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'counted_at' => $this->counted_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'responsible' => $this->whenLoaded('responsible', fn () => $this->responsible ? ['id' => $this->responsible->id, 'name' => $this->responsible->name] : null),
            'product_group_id' => $this->product_group_id,
            'items_correct' => $this->items_correct,
            'items_adjusted' => $this->items_adjusted,
            'items_total' => $this->whenCounted('items'),
            'items_counted' => $this->when(isset($this->items_counted_count), fn () => (int) $this->items_counted_count),
            'adjustment_value' => (float) $this->adjustment_value,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (StockCountItem $item): array => [
                'id' => $item->id,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'gtin' => $item->product->gtin,
                    'code' => $item->product->code,
                    'unit_of_sale' => $item->product->unit_of_sale,
                ] : null,
                'product_id' => $item->product_id,
                'system_quantity' => $item->system_quantity,
                'counted_quantity' => $item->counted_quantity,
                'difference' => $item->difference,
                'adjusted' => $item->adjusted,
            ])->values()),
        ];
    }
}
