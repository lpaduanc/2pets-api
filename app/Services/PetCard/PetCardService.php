<?php

namespace App\Services\PetCard;

use App\Models\Pet;

final class PetCardService
{
    public function __construct(
        private readonly QRCodeService $qrCodeService
    ) {}

    public function getPublicCardData(Pet $pet): array
    {
        return [
            'id' => $pet->public_id,
            'name' => $pet->name,
            'species' => $pet->species,
            'breed' => $pet->breed,
            'photo' => $pet->photo_url,
            'allergies' => $pet->getAllergies(),
            'is_lost' => $pet->is_lost,
            'lost_alert' => $pet->lost_alert_message,
            'emergency_contact' => [
                'name' => $pet->user->name,
                'phone' => $pet->user->phone,
                'email' => $pet->user->email,
            ],
            'vaccination_status' => $this->getVaccinationStatus($pet),
            'qr_code_url' => $this->qrCodeService->generateQRCodeUrl(
                $this->qrCodeService->generatePetCardUrl($pet->public_id)
            ),
        ];
    }

    public function markAsLost(Pet $pet, string $message): void
    {
        $pet->update([
            'is_lost' => true,
            'lost_alert_message' => $message,
            'lost_since' => now(),
        ]);

        // TODO: Notify nearby professionals about lost pet
    }

    public function markAsFound(Pet $pet): void
    {
        $pet->update([
            'is_lost' => false,
            'lost_alert_message' => null,
            'lost_since' => null,
        ]);
    }

    private function getVaccinationStatus(Pet $pet): string
    {
        $latestVaccination = $pet->vaccinations()
            ->orderBy('application_date', 'desc')
            ->first();

        if (!$latestVaccination) {
            return 'No vaccinations registered';
        }

        if ($latestVaccination->next_dose_date && $latestVaccination->next_dose_date->isPast()) {
            return 'Vaccination overdue';
        }

        if ($latestVaccination->next_dose_date) {
            return "Next vaccination: {$latestVaccination->next_dose_date->format('d/m/Y')}";
        }

        return 'Vaccination up to date';
    }
}

