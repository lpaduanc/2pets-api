<?php

namespace App\Http\Resources\Commercial;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_editable' => $this->status->isEditable(),
            'fiscal_operation' => $this->fiscal_operation->value,
            'fiscal_operation_label' => $this->fiscal_operation->label(),

            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $this->client->phone,
            ] : null),
            'client_id' => $this->client_id,
            'pet' => $this->whenLoaded('pet', fn () => $this->pet ? [
                'id' => $this->pet->id,
                'name' => $this->pet->name,
                'species' => $this->pet->species,
            ] : null),
            'pet_id' => $this->pet_id,

            'cash_register_id' => $this->cash_register_id,

            'discount_type' => $this->discount_type->value,
            'discount_value' => (float) $this->discount_value,
            'discount_amount' => (float) $this->discount_amount,
            'subtotal' => (float) $this->subtotal,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'amount_due' => $this->amountDue(),

            'printed_notes' => $this->printed_notes,
            'notes' => $this->notes,
            'valid_until' => $this->valid_until?->toDateString(),
            'converted_to_sale_id' => $this->converted_to_sale_id,

            'sold_at' => $this->sold_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),

            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'receipts' => SaleReceiptResource::collection($this->whenLoaded('receipts')),

            // Alimenta o filtro de pendencia fiscal da consulta (doc 01). Hoje sempre null
            // porque a emissao pertence ao doc 05 — o campo ja existe para que a tela nao
            // precise trocar de contrato quando aquele documento entrar.
            'fiscal_pending' => null,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
