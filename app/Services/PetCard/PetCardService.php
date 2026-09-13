<?php

namespace App\Services\PetCard;

use App\Models\Pet;

final class PetCardService
{
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
}
