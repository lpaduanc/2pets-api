<?php

namespace App\Http\Resources\Commercial;

use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // "product"/"service", não o FQCN: o app não precisa conhecer namespace PHP, e
            // expor a classe seria dar pista de estrutura interna sem ganho nenhum.
            'sellable_type' => $this->sellable_type === null ? null : mb_strtolower(class_basename($this->sellable_type)),
            'sellable_id' => $this->sellable_id,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'commission_percent' => $this->commission_percent === null ? null : (float) $this->commission_percent,
            'staff' => $this->whenLoaded('staff', fn () => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->relationLoaded('user') ? $this->staff->user?->name : null,
                'role' => $this->staff->role?->value,
                'role_label' => $this->staff->role?->label(),
            ] : null),
            'staff_id' => $this->staff_id,
        ];
    }
}
