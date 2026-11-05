<?php

namespace App\Http\Resources\Commercial;

use App\Models\SaleReceipt;
use App\Services\Commercial\Reversal\ReceiptCancellability;
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
            // Estorno de um recebimento (PLANO 2.3). `cancelled_at` = `reversed_at` (estornado aqui
            // ou no cancelamento da venda).
            'cancelled_at' => $this->reversed_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy === null ? null : ['id' => $this->cancelledBy->id, 'name' => $this->cancelledBy->name],
                fn () => $this->cancelled_by === null ? null : ['id' => $this->cancelled_by, 'name' => null]),
            'can_cancel' => $this->cancelBlockedReason($request) === null,
            'cancel_blocked_reason' => $this->cancelBlockedReason($request),
        ];
    }

    private function cancelBlockedReason(Request $request): ?string
    {
        // Singleton por request: a permissão é lida uma vez por venda, não por recebimento.
        return app(ReceiptCancellability::class)->blockedReason($this->resource, $request->user());
    }
}
