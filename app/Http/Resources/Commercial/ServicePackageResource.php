<?php

namespace App\Http\Resources\Commercial;

use App\Models\ServicePackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServicePackage
 */
class ServicePackageResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['items.service:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,

            'validity_type' => $this->validity_type->value,
            'validity_type_label' => $this->validity_type->label(),
            'validity_days' => $this->validity_days,
            'fixed_expires_at' => $this->fixed_expires_at?->toDateString(),

            'allow_transfer_between_pets' => $this->allow_transfer_between_pets,
            'commission_percent' => $this->commission_percent === null ? null : (float) $this->commission_percent,
            'show_in_price_list' => $this->show_in_price_list,
            'active' => $this->active,

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'service_id' => $item->service_id,
                'service_name' => $item->service?->name,
                'quantity' => $item->quantity,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
