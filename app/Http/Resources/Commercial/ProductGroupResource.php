<?php

namespace App\Http\Resources\Commercial;

use App\Models\ProductGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductGroup
 */
class ProductGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'default_markup_percent' => $this->default_markup_percent === null ? null : (float) $this->default_markup_percent,
            'effective_markup_percent' => $this->effectiveMarkupPercent(),
            'active' => $this->active,
            // PLANO 2.4: herdado por produto/serviço do grupo sem centro próprio.
            'cost_center_id' => $this->cost_center_id,
            'children' => self::collection($this->whenLoaded('children')),
            'products_count' => $this->whenCounted('products'),
            'services_count' => $this->whenCounted('services'),
        ];
    }
}
