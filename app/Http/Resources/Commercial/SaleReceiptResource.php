<?php

namespace App\Http\Resources\Commercial;

use App\Models\SaleReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleReceipt
 */
class SaleReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'operator_fee' => (float) $this->operator_fee,
            'net_amount' => (float) $this->net_amount,
            'installments' => $this->installments,
            'received_at' => $this->received_at?->toIso8601String(),
            'expected_settlement_date' => $this->expected_settlement_date?->toDateString(),
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
                'kind' => $this->paymentMethod->kind->value,
                'kind_label' => $this->paymentMethod->kind->label(),
                'acquirer' => $this->paymentMethod->acquirer,
            ]),
            'payment_method_id' => $this->payment_method_id,
            'account_id' => $this->account_id,
            'notes' => $this->notes,
        ];
    }
}
