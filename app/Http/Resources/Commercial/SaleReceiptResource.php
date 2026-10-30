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
            // Parte do `amount` que é repasse da taxa ao cliente (plano 1.1); string de 2 casas.
            'surcharge_amount' => $this->surcharge_amount ?? '0.00',
            'operator_fee' => (float) $this->operator_fee,
            'net_amount' => (float) $this->net_amount,
            'installments' => $this->installments,
            'card_brand' => $this->card_brand?->value,
            'authorization_code' => $this->authorization_code,
            'terminal_label' => $this->terminal_label,
            'fee_percent_applied' => $this->fee_percent_applied,
            'receivables' => ReceivableResource::collection($this->whenLoaded('receivables')),
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
