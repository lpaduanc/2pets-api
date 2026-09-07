<?php

namespace App\Http\Resources;

use Carbon\Carbon;
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
            'opening_hours' => $this->formatTime($this->opening_hours),
            'closing_hours' => $this->formatTime($this->closing_hours),
            'technical_responsible_id' => $this->technical_responsible_id,
            'technical_responsible_name' => $this->technical_responsible_name,
            'technical_responsible_crmv' => $this->technical_responsible_crmv,
            'technical_responsible_crmv_state' => $this->technical_responsible_crmv_state,
            'average_rating' => (float) $this->average_rating,
            'total_reviews' => $this->total_reviews,
        ];
    }

    /**
     * A coluna `professionals.opening_hours`/`closing_hours` é `time` no
     * Postgres e chega crua do PDO como string `H:i:s` (ex.: `08:00:00`),
     * sem cast no model. Normaliza para `H:i` para que o mesmo payload
     * devolvido por este resource seja aceito de volta por
     * `UpdateProfileRequest` — round-trip GET → PUT sem 422.
     */
    private function formatTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return Carbon::parse($time)->format('H:i');
    }
}
