<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bloco `company` do contrato de `GET/PUT /api/profile`.
 */
class CompanyProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'company_name' => $this->company_name,
            'cnpj' => $this->cnpj,
            'contact_name' => $this->contact_name,
            'contact_position' => $this->contact_position,
            'phone' => $this->phone,
            'website' => $this->website,
            'employee_count' => $this->employee_count,
            'benefit_type' => $this->benefit_type,
            'notes' => $this->notes,
        ];
    }
}
