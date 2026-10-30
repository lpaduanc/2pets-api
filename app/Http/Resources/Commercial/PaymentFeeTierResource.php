<?php

namespace App\Http\Resources\Commercial;

use App\Models\PaymentMethodFeeTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentMethodFeeTier */
class PaymentFeeTierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'card_brand' => $this->card_brand?->value,
            'installments_from' => $this->installments_from,
            'installments_to' => $this->installments_to,
            'fee_percent' => $this->fee_percent,
            'fee_fixed' => $this->fee_fixed,
            'first_settlement_days' => $this->first_settlement_days,
            'interval_days' => $this->interval_days,
            'pass_to_customer' => $this->pass_to_customer,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
        ];
    }
}
