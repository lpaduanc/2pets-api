<?php

namespace App\Http\Resources\Commercial;

use App\Models\CommissionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionRule
 */
class CommissionRuleResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['staff.user:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->user?->name),

            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'scope_id' => $this->scope_id,

            'percent' => $this->percent === null ? null : (float) $this->percent,
            'fixed_amount' => $this->fixed_amount === null ? null : (float) $this->fixed_amount,
            'calculation_base' => $this->calculation_base->value,
            'calculation_base_label' => $this->calculation_base->label(),
            'only_when_received' => $this->only_when_received,

            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'active' => $this->active,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
