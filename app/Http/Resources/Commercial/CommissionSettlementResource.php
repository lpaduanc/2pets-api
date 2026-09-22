<?php

namespace App\Http\Resources\Commercial;

use App\Models\CommissionSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionSettlement
 */
class CommissionSettlementResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['staff.user:id,name', 'items.saleItem', 'items.commissionRule'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->user?->name),

            'period_from' => $this->period_from->toDateString(),
            'period_to' => $this->period_to->toDateString(),
            'received_until' => $this->received_until->toDateString(),
            'total_amount' => (float) $this->total_amount,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'closed_at' => $this->closed_at?->toIso8601String(),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sale_item_id' => $item->sale_item_id,
                'description' => $item->saleItem?->description,
                'commission_rule_id' => $item->commission_rule_id,
                'base_amount' => (float) $item->base_amount,
                'commission_amount' => (float) $item->commission_amount,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
