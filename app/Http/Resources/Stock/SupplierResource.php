<?php

namespace App\Http\Resources\Stock;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'display_name' => $this->displayName(),
            'document' => $this->document,
            'state_registration' => $this->state_registration,
            'phone' => $this->phone,
            'email' => $this->email,
            'sales_rep_name' => $this->sales_rep_name,
            'sales_rep_phone' => $this->sales_rep_phone,
            'sales_rep_email' => $this->sales_rep_email,
            'address_zip' => $this->address_zip,
            'address_street' => $this->address_street,
            'address_number' => $this->address_number,
            'address_complement' => $this->address_complement,
            'address_district' => $this->address_district,
            'address_city' => $this->address_city,
            'address_state' => $this->address_state,
            'payment_terms' => $this->payment_terms,
            'lead_time_days' => $this->lead_time_days,
            'notes' => $this->notes,
            'active' => $this->active,
            'purchases_count' => $this->whenCounted('purchases'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
