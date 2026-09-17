<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §12.3: `status`
 * cru (nunca recebe `overdue` por escrita nova) + `is_overdue` calculado na leitura. O
 * frontend rotula "Vencida" a partir de `is_overdue`, não de um sexto valor de `status`.
 *
 * `amount_paid`/`balance_due`/`credit_balance` (contrato docs/atendimento-veterinario/
 * 11-internacao-no-fluxo-de-faturamento.md §3-bis.3) são sempre derivados de
 * `Invoice::payments()` — zero custo extra para fatura sem adiantamento
 * (`amount_paid` é sempre `0` ou igual a `total`, igual a antes desta coluna existir).
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'items' => $this->items,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'amount_paid' => $this->resource->amountPaid(),
            'balance_due' => $this->resource->balanceDue(),
            'credit_balance' => $this->resource->creditBalance(),
            'status' => $this->status,
            'is_overdue' => $this->resource->isOverdue(),
            'payment_method' => $this->payment_method,
            'payment_date' => $this->payment_date?->toDateString(),
            'payment_channel' => $this->payment_channel,
            'notes' => $this->notes,
            'medical_record_id' => $this->medical_record_id,
            'appointment_id' => $this->appointment_id,
            'organization_id' => $this->organization_id,
            'client_id' => $this->client_id,
            'professional_id' => $this->professional_id,

            'client' => new UserResource($this->whenLoaded('client')),
            'professional' => new UserResource($this->whenLoaded('professional')),
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
