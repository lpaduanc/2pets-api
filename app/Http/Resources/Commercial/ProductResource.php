<?php

namespace App\Http\Resources\Commercial;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** Relações que a listagem do doc 08 precisa para não cair em N+1. */
    public const RESOURCE_RELATIONS = ['group:id,name', 'brand:id,name', 'category:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'code' => $this->code,
            'gtin' => $this->gtin,
            'ncm' => $this->ncm,
            'cest' => $this->cest,
            'unit_of_sale' => $this->unit_of_sale,
            'purpose' => $this->purpose?->value,
            'purpose_label' => $this->purpose?->label(),

            'group' => $this->whenLoaded('group', fn () => [
                'id' => $this->group->id,
                'name' => $this->group->name,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'product_group_id' => $this->product_group_id,
            'brand_id' => $this->brand_id,

            'price' => (float) $this->price,
            'average_cost' => (float) $this->average_cost,
            'last_cost' => (float) $this->last_cost,
            'markup_percent' => $this->markup_percent === null ? null : (float) $this->markup_percent,
            'commission_percent' => $this->commission_percent === null ? null : (float) $this->commission_percent,

            'stock_quantity' => $this->stock_quantity,
            'min_stock' => $this->min_stock,
            'max_stock' => $this->max_stock,
            'stock_situation' => $this->stockSituation(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_expired' => $this->isExpired(),

            'show_in_price_list' => $this->show_in_price_list,
            'allow_price_override' => $this->allow_price_override,
            'controls_stock' => $this->controls_stock,
            'track_batches' => $this->track_batches,
            'is_active' => $this->is_active,

            'images' => $this->images,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
