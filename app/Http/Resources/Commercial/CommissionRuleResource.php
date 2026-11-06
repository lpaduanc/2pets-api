<?php

namespace App\Http\Resources\Commercial;

use App\Enums\CommissionTrigger;
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
            'trigger' => $this->trigger->value,
            'trigger_label' => $this->trigger->label(),
            // Deprecado (PLANO 2.6): derivado do gatilho só para cliente antigo; use `trigger`.
            'only_when_received' => $this->trigger === CommissionTrigger::ON_RECEIPT,

            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'active' => $this->active,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
