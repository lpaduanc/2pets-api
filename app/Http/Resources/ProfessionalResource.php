<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'professional_type' => $this->professional_type,
            'business_name' => $this->business_name,
            'cnpj' => $this->cnpj,
            'description' => $this->description,

            // Veterinary details
            'crmv' => $this->crmv,
            'crmv_state' => $this->crmv_state,
            'university' => $this->university,
            'graduation_year' => $this->graduation_year,
            'courses' => $this->courses ?? [],
            'specialties' => $this->specialties ?? [],
            'experience_years' => $this->experience_years,

            // Service area
            'service_radius_km' => $this->service_radius_km,

            // Schedule
            'opening_hours' => $this->opening_hours,
            'closing_hours' => $this->closing_hours,
            'working_days' => $this->working_days ?? [],

            // Offerings
            'services_offered' => $this->services_offered ?? [],
            'products_sold' => $this->products_sold ?? [],
            'equipment' => $this->equipment ?? [],
            'certifications' => $this->certifications ?? [],

            // Technical responsible (clinic / laboratory)
            'technical_responsible' => $this->when(
                $this->technical_responsible_id || $this->technical_responsible_name,
                [
                    'id' => $this->technical_responsible_id,
                    'name' => $this->technical_responsible_name,
                    'crmv' => $this->technical_responsible_crmv,
                    'crmv_state' => $this->technical_responsible_crmv_state,
                ]
            ),

            // Ratings
            'average_rating' => $this->average_rating ? (float) $this->average_rating : 0,
            'total_reviews' => $this->total_reviews ?? 0,
            'is_featured' => $this->is_featured ?? false,

            // Related user data (when loaded via nested relationship)
            'user' => new UserResource($this->whenLoaded('user')),

            // Services relation
            'services' => $this->when(
                $this->relationLoaded('services'),
                fn () => $this->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'duration' => $service->duration,
                    'price' => $service->price,
                    'description' => $service->description,
                ])
            ),

            // Distance (appended by geo-search queries)
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn () => round($this->distance_km, 2)
            ),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
