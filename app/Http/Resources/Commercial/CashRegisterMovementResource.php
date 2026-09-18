<?php

namespace App\Http\Resources\Commercial;

use App\Models\CashRegisterMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegisterMovement
 */
class CashRegisterMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => (float) $this->amount,
            // O front nunca recalcula o sinal: quem decide a direção é o enum no servidor.
            'signed_amount' => $this->signedAmount(),
            'is_physical_cash' => $this->isPhysicalCash(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'description' => $this->description,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
                'kind' => $this->paymentMethod->kind->value,
            ] : null),
            'payment_method_id' => $this->payment_method_id,
            'account_id' => $this->account_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'reference_type' => $this->reference_type === null ? null : class_basename($this->reference_type),
            'reference_id' => $this->reference_id,
        ];
    }
}
