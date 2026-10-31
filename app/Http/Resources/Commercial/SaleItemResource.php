<?php

namespace App\Http\Resources\Commercial;

use App\Models\Product;
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
            'appointment_id' => $this->appointment_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            // Casas que a quantidade aceita (docs/gap-petflow 0.2): granel mostra campo decimal no
            // PDV, unidade mostra +/-. Só produto restringe; serviço e pacote vêm `null`.
            'unit_of_sale' => $this->whenLoaded('sellable', fn () => $this->sellable instanceof Product ? $this->sellable->unit_of_sale : null),
            'decimal_places_allowed' => $this->whenLoaded('sellable', fn () => $this->sellable instanceof Product ? (int) $this->sellable->decimal_places_allowed : null),
            'unit_price' => $this->unit_price,
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
            // PLANO 1.4: líquido < quantidade × custo e não é cortesia — o recebimento recusa.
            'below_cost' => $this->isBelowCost(),
            'courtesy_kind' => $this->courtesy_kind?->value,
            'courtesy_reason' => $this->courtesy_reason,
            'courtesy_original_unit_price' => $this->courtesy_original_unit_price,
            'courtesy_marked_by' => $this->whenLoaded('courtesyMarkedBy', fn () => $this->courtesyMarkedBy === null ? null : [
                'id' => $this->courtesyMarkedBy->id,
                'name' => $this->courtesyMarkedBy->name,
            ], fn () => $this->courtesy_marked_by === null ? null : ['id' => $this->courtesy_marked_by, 'name' => null]),
            'courtesy_marked_at' => $this->courtesy_marked_at?->toIso8601String(),
        ];
    }
}
