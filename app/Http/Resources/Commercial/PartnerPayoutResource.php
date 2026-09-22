<?php

namespace App\Http\Resources\Commercial;

use App\Models\PartnerPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PartnerPayout
 */
class PartnerPayoutResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['partner:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner' => $this->whenLoaded('partner', fn () => ['id' => $this->partner->id, 'name' => $this->partner->name]),
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
