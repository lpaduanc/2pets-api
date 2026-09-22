<?php

namespace App\Http\Resources\Commercial;

use App\Models\SoldPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SoldPackage
 */
class SoldPackageResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['servicePackage', 'client:id,name', 'pet:id,name', 'items.service:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->effectiveStatus();

        return [
            'id' => $this->id,
            'service_package' => $this->whenLoaded('servicePackage', fn () => [
                'id' => $this->servicePackage->id,
                'name' => $this->servicePackage->name,
            ]),
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'pet' => $this->whenLoaded('pet', fn () => ['id' => $this->pet->id, 'name' => $this->pet->name]),

            'sold_at' => $this->sold_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toDateString(),

            'status' => $status->value,
            'status_label' => $status->label(),
            'total_remaining' => $this->totalRemaining(),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'service_name' => $item->service?->name,
                'quantity_total' => $item->quantity_total,
                'quantity_used' => $item->quantity_used,
                'quantity_remaining' => $item->quantityRemaining(),
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
