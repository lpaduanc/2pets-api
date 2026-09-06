<?php

namespace App\Services\LostPet;

use App\Jobs\NotifyNearbyUsersOfLostPetAlert;
use App\Models\FoundPetReport;
use App\Models\LostPetAlert;
use App\Models\Pet;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Search\GeoLocationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LostPetAlertService
{
    public function __construct(
        private NotificationService $notificationService,
        private GeoLocationService $geoLocationService
    ) {}

    public function createAlert(Pet $pet, array $data): LostPetAlert
    {
        $alert = DB::transaction(function () use ($pet, $data) {
            // Mark pet as lost
            $pet->update([
                'is_lost' => true,
                'lost_since' => now(),
                'lost_alert_message' => $data['description'] ?? null,
            ]);

            return LostPetAlert::create([
                'pet_id' => $pet->id,
                'user_id' => $pet->user_id,
                'status' => 'active',
                ...$data,
            ]);
        });

        // Notificar usuarios proximos so DEPOIS do commit: full scan de
        // `users` + N notificacoes nao pode segurar o lock da transacao que
        // criou o alerta (ver notifyNearbyUsers()).
        $this->notifyNearbyUsers($alert);

        return $alert;
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
            'Possível Avistamento do '.$alert->pet->name,
            'Alguém reportou ter visto seu pet! Verifique os detalhes no app.',
            ['email', 'push'],
            ['alert_id' => $alert->id, 'report_id' => $report->id]
        );

        return $report;
    }

    /**
     * `ST_DWithin` no WHERE aproveita o indice GIST PARCIAL de
     * `lost_pet_alerts.last_seen_geo` (`WHERE status = 'active'`, migration
     * `2026_09_06_000202_...`) — o Haversine cru anterior usava `HAVING`,
     * avaliado apos a projecao para toda linha, sem indice possivel.
     */
    public function getNearbyAlerts(float $latitude, float $longitude, float $radiusKm = 10): Collection
    {
        $dWithin = $this->geoLocationService->dWithinExpression('lost_pet_alerts.last_seen_geo', $latitude, $longitude, $radiusKm);
        $distance = $this->geoLocationService->distanceExpression('lost_pet_alerts.last_seen_geo', $latitude, $longitude);

        return LostPetAlert::select('lost_pet_alerts.*')
            ->selectRaw("({$distance['sql']}) / 1000 AS distance_km", $distance['bindings'])
            ->where('status', 'active')
            ->whereRaw($dWithin['sql'], $dWithin['bindings'])
            ->with(['pet', 'user'])
            ->orderBy('distance_km')
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

    /**
     * Varre `users` inteira (~200k linhas no benchmark) por proximidade — a
     * mesma consulta usava Haversine cru em `HAVING`, full scan sempre. Agora
     * `ST_DWithin` usa o indice GIST de `users.location` (migration
     * `2026_04_04_000002_...`).
     *
     * O disparo em si roda no worker (`queue:work`), nao aqui: com o volume
     * de usuarios, notificar de forma sincrona bloquearia o request que
     * criou o alerta pelo tempo de notificar todo mundo dentro do raio. Ver
     * {@see NotifyNearbyUsersOfLostPetAlert::notifyUsersWithinRadius()} para
     * o `chunkById` que efetivamente varre e notifica.
     */
    private function notifyNearbyUsers(LostPetAlert $alert): void
    {
        if (! $alert->last_seen_latitude || ! $alert->last_seen_longitude) {
            return;
        }

        NotifyNearbyUsersOfLostPetAlert::dispatch($alert->id);
    }
}
