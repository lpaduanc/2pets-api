<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bloco `professional` do contrato de `GET/PUT /api/profile`.
 *
 * `is_crmv_verified`, `average_rating` e `total_reviews` são somente leitura:
 * aparecem aqui para exibição, mas `UpdateProfileRequest` não declara regra
 * de validação para eles, então `validated()` os descarta antes de chegar
 * a qualquer service — não há como um PUT alterá-los por este endpoint.
 */
class ProfessionalProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'professional_type' => $this->professional_type,
            'business_name' => $this->business_name,
            'cnpj' => $this->cnpj,
            'crmv' => $this->crmv,
            'crmv_state' => $this->crmv_state,
            'is_crmv_verified' => $this->is_crmv_verified,
            'specialties' => $this->specialties ?? [],
            'services_offered' => $this->services_offered ?? [],
            'service_radius_km' => $this->service_radius_km,
            'description' => $this->description,
            'university' => $this->university,
            'graduation_year' => $this->graduation_year,
            'experience_years' => $this->experience_years,
            'working_days' => $this->working_days ?? [],
            'opening_hours' => $this->opening_hours,
            'closing_hours' => $this->closing_hours,
            'average_rating' => (float) $this->average_rating,
            'total_reviews' => $this->total_reviews,
        ];
    }
}
