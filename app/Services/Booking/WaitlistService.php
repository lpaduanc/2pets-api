<?php

namespace App\Services\Booking;

use App\Enums\WaitlistStatus;
use App\Models\Waitlist;
use Carbon\Carbon;

final class WaitlistService
{
    public function addToWaitlist(
        int $professionalId,
        int $clientId,
        ?int $serviceId,
        ?int $petId,
        Carbon $preferredDate,
        ?string $preferredTime,
        ?string $notes
    ): Waitlist {
        $waitlist = Waitlist::create([
            'professional_id' => $professionalId,
            'client_id' => $clientId,
            'service_id' => $serviceId,
            'pet_id' => $petId,
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
            'status' => WaitlistStatus::ACTIVE->value,
            'notes' => $notes,
        ]);

        return $waitlist;
    }

    public function markAsNotified(int $waitlistId): void
    {
        $waitlist = Waitlist::findOrFail($waitlistId);

        $waitlist->update([
            'status' => WaitlistStatus::NOTIFIED->value,
        ]);
    }

    public function markAsBooked(int $waitlistId): void
    {
        $waitlist = Waitlist::findOrFail($waitlistId);

        $waitlist->update([
            'status' => WaitlistStatus::BOOKED->value,
        ]);
    }

    public function cancelWaitlist(int $waitlistId): void
    {
        $waitlist = Waitlist::findOrFail($waitlistId);

        $waitlist->update([
            'status' => WaitlistStatus::CANCELLED->value,
        ]);
    }

    public function getActiveWaitlist(int $professionalId, Carbon $date)
    {
        return Waitlist::where('professional_id', $professionalId)
            ->where('preferred_date', $date)
            ->where('status', WaitlistStatus::ACTIVE->value)
            ->orderBy('created_at')
            ->get();
    }
}

