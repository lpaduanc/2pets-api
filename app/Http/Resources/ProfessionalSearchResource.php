<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->formatAddress(),
            'city' => $this->city,
            'state' => $this->state,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_km' => $this->distance_km ? round($this->distance_km, 2) : null,
            'professional' => [
                'type' => $this->professional?->professional_type,
                'business_name' => $this->professional?->business_name,
                'description' => $this->professional?->description,
                'specialties' => $this->professional?->specialties,
                'experience_years' => $this->professional?->experience_years,
                'crmv' => $this->professional?->crmv,
                'crmv_state' => $this->professional?->crmv_state,
                'working_days' => $this->professional?->working_days,
                'opening_hours' => $this->professional?->opening_hours,
                'closing_hours' => $this->professional?->closing_hours,
                'service_radius_km' => $this->professional?->service_radius_km,
                'average_rating' => 0, // Placeholder for Phase 2
                'total_reviews' => 0, // Placeholder for Phase 2
            ],
            'services' => $this->professional?->services->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'duration' => $service->duration,
                    'price' => $service->price,
                    'description' => $service->description,
                ];
            }),
            'availability' => [
                'has_online_booking' => false, // Will be true after implementing booking system
                'next_available_slot' => null, // Will be calculated in booking system
            ],
        ];
    }

    private function formatAddress(): string
    {
        $parts = array_filter([
            $this->address,
            $this->number,
            $this->neighborhood,
        ]);

        return implode(', ', $parts);
    }
}
