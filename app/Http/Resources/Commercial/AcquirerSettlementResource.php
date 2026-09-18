<?php

namespace App\Http\Resources\Commercial;

use App\Models\AcquirerSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AcquirerSettlement
 */
class AcquirerSettlementResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['paymentMethod', 'destinationAccount:id,name', 'reconciledBy:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deposit_date' => $this->deposit_date?->toDateString(),
            'description' => $this->description,
            'gross_amount' => (float) $this->gross_amount,
            'fee_amount' => (float) $this->fee_amount,
            'net_amount' => (float) $this->net_amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'divergence_note' => $this->divergence_note,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'reconciled_by' => $this->whenLoaded('reconciledBy', fn () => $this->reconciledBy ? [
                'id' => $this->reconciledBy->id,
                'name' => $this->reconciledBy->name,
            ] : null),
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
                'acquirer' => $this->paymentMethod->acquirer,
            ]),
            'destination_account' => $this->whenLoaded('destinationAccount', fn () => $this->destinationAccount ? [
                'id' => $this->destinationAccount->id,
                'name' => $this->destinationAccount->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'sale_receipt_id' => $item->sale_receipt_id,
                'amount' => (float) $item->amount,
            ])),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
