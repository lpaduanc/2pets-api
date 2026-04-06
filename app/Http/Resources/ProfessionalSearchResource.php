<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $professional = $this->professional;
        $minPrice = $professional?->services?->where('active', true)->min('price');

        return [
            'id' => $this->id,
            'name' => $professional?->business_name ?: $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url ?? null,
            'bio' => $professional?->description,
            'address' => $this->formatAddress(),
            'city' => $this->city,
            'state' => $this->state,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_km' => $this->distance_km ? round($this->distance_km, 2) : null,
            'average_rating' => (float) ($professional?->average_rating ?? 0),
            'reviews_count' => (int) ($professional?->total_reviews ?? 0),
            'starting_price' => $minPrice ? (float) $minPrice : null,
            'verified' => !empty($professional?->crmv),
            'is_featured' => (bool) ($professional?->is_featured ?? false),
            'professional_type' => $professional?->professional_type,
            'professional_type_label' => $this->getProfessionalTypeLabel($professional?->professional_type),
            'professional' => [
                'type' => $professional?->professional_type,
                'business_name' => $professional?->business_name,
                'description' => $professional?->description,
                'specialties' => $professional?->specialties ?? [],
                'experience_years' => $professional?->experience_years,
                'crmv' => $professional?->crmv,
                'crmv_state' => $professional?->crmv_state,
                'working_days' => $professional?->working_days ?? [],
                'opening_hours' => $professional?->opening_hours,
                'closing_hours' => $professional?->closing_hours,
                'service_radius_km' => $professional?->service_radius_km,
                'average_rating' => (float) ($professional?->average_rating ?? 0),
                'total_reviews' => (int) ($professional?->total_reviews ?? 0),
            ],
            'services' => $professional?->services?->where('active', true)->map(fn ($service) => [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category,
                'duration' => $service->duration,
                'price' => (float) $service->price,
                'description' => $service->description,
            ])->values() ?? [],
            'availability' => [
                'has_online_booking' => true,
                'is_open_now' => $this->isOpenNow($professional),
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

    private function getProfessionalTypeLabel(?string $type): string
    {
        return match ($type) {
            'veterinarian' => 'Veterinário',
            'clinic' => 'Clínica Veterinária',
            'petshop' => 'Pet Shop',
            'groomer' => 'Banho e Tosa',
            'trainer' => 'Adestrador',
            'pet_sitter' => 'Pet Sitter',
            'daycare' => 'Creche/Hotel',
            default => 'Profissional',
        };
    }

    private function isOpenNow(?object $professional): bool
    {
        if (!$professional?->working_days || !$professional?->opening_hours || !$professional?->closing_hours) {
            return false;
        }

        $now = now();
        $dayMap = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $today = $dayMap[$now->dayOfWeek] ?? '';

        if (!in_array($today, $professional->working_days ?? [])) {
            return false;
        }

        $currentTime = $now->format('H:i');
        return $currentTime >= $professional->opening_hours && $currentTime <= $professional->closing_hours;
    }
}
