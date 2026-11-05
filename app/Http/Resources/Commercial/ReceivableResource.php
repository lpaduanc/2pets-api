<?php

namespace App\Http\Resources\Commercial;

use App\Models\Receivable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Receivable */
class ReceivableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->whenLoaded('receipt', fn () => $this->receipt?->sale_id),
            'sale_number' => $this->whenLoaded('receipt', fn () => $this->receipt?->sale?->number),
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
            ] : null),
            'card_brand' => $this->card_brand?->value,
            'installment_number' => $this->installment_number,
            'installments_count' => $this->installments_count,
            'gross_amount' => $this->gross_amount,
            'fee_amount' => $this->fee_amount,
            'net_amount' => $this->net_amount,
            'expected_date' => $this->expected_date?->toDateString(),
            'status' => $this->status->value,
            'settled_at' => $this->settled_at?->toIso8601String(),
            // Antecipação vigente (PLANO 2.1): com ela, `expected_date` é a data da antecipação.
            'anticipation_id' => $this->anticipation_id,
            'anticipation' => $this->whenLoaded('anticipation', fn () => $this->anticipation === null ? null : [
                'id' => $this->anticipation->id,
                'anticipated_at' => $this->anticipation->anticipated_at->toDateString(),
                'fee_amount' => (string) $this->anticipation->items->firstWhere('receivable_id', $this->id)?->fee_amount,
                'original_expected_date' => $this->anticipation->items->firstWhere('receivable_id', $this->id)?->original_expected_date?->toDateString(),
            ]),
        ];
    }
}
