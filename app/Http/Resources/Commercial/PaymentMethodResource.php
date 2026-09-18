<?php

namespace App\Http\Resources\Commercial;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'acquirer' => $this->acquirer,
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'default_account_id' => $this->default_account_id,
            'default_account' => $this->whenLoaded('defaultAccount', fn () => $this->defaultAccount ? [
                'id' => $this->defaultAccount->id,
                'name' => $this->defaultAccount->name,
            ] : null),
            'fee_percent' => (float) $this->fee_percent,
            'fee_fixed' => (float) $this->fee_fixed,
            'settlement_days' => $this->settlement_days,
            'max_installments' => $this->max_installments,
            'display_order' => $this->display_order,

            // Regras que o PDV precisa para montar o modal de recebimento sem reimplementar
            // o enum em JavaScript.
            'is_physical_cash' => $this->kind->isPhysicalCash(),
            'allows_installments' => $this->kind->allowsInstallments(),
            'settles_through_acquirer' => $this->kind->settlesThroughAcquirer(),
            'defers_to_client_account' => $this->kind->isDeferredToClientAccount(),

            'active' => $this->active,
        ];
    }
}
