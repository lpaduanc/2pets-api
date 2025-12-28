<?php

namespace App\Services\LostPet;

use App\Models\LostPetAlert;
use App\Models\FoundPetReport;
use App\Models\Pet;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LostPetAlertService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function createAlert(Pet $pet, array $data): LostPetAlert
    {
        return DB::transaction(function () use ($pet, $data) {
            // Mark pet as lost
            $pet->update([
                'is_lost' => true,
                'lost_since' => now(),
                'lost_alert_message' => $data['description'] ?? null,
            ]);

            $alert = LostPetAlert::create([
                'pet_id' => $pet->id,
                'user_id' => $pet->user_id,
                'status' => 'active',
                ...$data,
            ]);

            // Notify nearby users
            $this->notifyNearbyUsers($alert);

            return $alert;
        });
    }

    public function reportFound(
        LostPetAlert $alert,
        array $reportData,
        ?User $reporter = null
    ): FoundPetReport {
        $report = FoundPetReport::create([
            'lost_pet_alert_id' => $alert->id,
            'reporter_user_id' => $reporter?->id,
            ...$reportData,
        ]);

        // Notify pet owner
        $this->notificationService->send(
            $alert->user,
            'lost_pet_report',
            'Possível Avistamento do ' . $alert->pet->name,
            "Alguém reportou ter visto seu pet! Verifique os detalhes no app.",
            ['email', 'push'],
            ['alert_id' => $alert->id, 'report_id' => $report->id]
        );

        return $report;
    }

    public function getNearbyAlerts(float $latitude, float $longitude, float $radiusKm = 10): Collection
    {
        return LostPetAlert::select('lost_pet_alerts.*')
            ->selectRaw('
                (6371 * acos(
                    cos(radians(?)) * 
                    cos(radians(last_seen_latitude)) * 
                    cos(radians(last_seen_longitude) - radians(?)) + 
                    sin(radians(?)) * 
                    sin(radians(last_seen_latitude))
                )) AS distance
            ', [$latitude, $longitude, $latitude])
            ->where('status', 'active')
            ->having('distance', '<=', $radiusKm)
            ->with(['pet', 'user'])
            ->orderBy('distance')
            ->get();
    }

    public function searchByMicrochip(string $microchipNumber): ?LostPetAlert
    {
        return LostPetAlert::where('microchip_number', $microchipNumber)
            ->where('status', 'active')
            ->with(['pet', 'user'])
            ->first();
    }

    public function getActiveAlerts(): Collection
    {
        return LostPetAlert::where('status', 'active')
            ->with(['pet', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    private function notifyNearbyUsers(LostPetAlert $alert): void
    {
        if (!$alert->last_seen_latitude || !$alert->last_seen_longitude) {
            return;
        }

        // Find users within alert radius
        $nearbyUsers = User::select('users.*')
            ->selectRaw('
                (6371 * acos(
                    cos(radians(?)) * 
                    cos(radians(latitude)) * 
                    cos(radians(longitude) - radians(?)) + 
                    sin(radians(?)) * 
                    sin(radians(latitude))
                )) AS distance
            ', [$alert->last_seen_latitude, $alert->last_seen_longitude, $alert->last_seen_latitude])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->having('distance', '<=', $alert->alert_radius_km)
            ->where('id', '!=', $alert->user_id)
            ->get();

        foreach ($nearbyUsers as $user) {
            $this->notificationService->send(
                $user,
                'lost_pet_alert',
                'Pet Perdido na sua Região',
                "{$alert->pet->name} está perdido próximo de você. Ajude a encontrá-lo!",
                ['push'],
                ['alert_id' => $alert->id]
            );
        }
    }
}

