<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\LostPetAlert;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Search\GeoLocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Notifica, em lotes, os usuarios com localizacao cadastrada dentro do raio
 * de um alerta de pet perdido (Fase 6 do plano de otimizacao de queries).
 *
 * Antes desta Fase, `LostPetAlertService::notifyNearbyUsers()` varria a
 * tabela `users` inteira (~200k linhas no benchmark) com Haversine cru em
 * `HAVING`, de forma sincrona, DENTRO da `DB::transaction()` que cria o
 * alerta — full scan segurando lock aberto, seguido de uma notificacao por
 * usuario encontrado no mesmo request. Aqui: `ST_DWithin` usa o indice GIST
 * de `users.location`, e o disparo roda no worker (`queue:work`), fora do
 * ciclo de vida do request HTTP.
 *
 * `chunkById`, nao `chunk`: e o padrao do repo para qualquer varredura de
 * volume, mesmo quando o loop nao altera as linhas paginadas — evita o
 * risco de outro processo alterar `users` no meio da paginacao por offset.
 */
final class NotifyNearbyUsersOfLostPetAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const USERS_PER_CHUNK = 500;

    public int $tries = 3;

    public function __construct(
        private readonly int $lostPetAlertId
    ) {}

    public function handle(NotificationService $notificationService, GeoLocationService $geoLocationService): void
    {
        $alert = LostPetAlert::with('pet')->find($this->lostPetAlertId);

        if ($alert === null || ! $alert->last_seen_latitude || ! $alert->last_seen_longitude) {
            return;
        }

        $this->notifyUsersWithinRadius($alert, $notificationService, $geoLocationService);
    }

    private function notifyUsersWithinRadius(
        LostPetAlert $alert,
        NotificationService $notificationService,
        GeoLocationService $geoLocationService
    ): void {
        $dWithin = $geoLocationService->dWithinExpression(
            'location',
            (float) $alert->last_seen_latitude,
            (float) $alert->last_seen_longitude,
            (float) $alert->alert_radius_km
        );

        User::query()
            ->whereRaw($dWithin['sql'], $dWithin['bindings'])
            ->where('id', '!=', $alert->user_id)
            ->chunkById(self::USERS_PER_CHUNK, function (Collection $users) use ($alert, $notificationService): void {
                $this->notifyEachUser($users, $alert, $notificationService);
            });
    }

    private function notifyEachUser(Collection $users, LostPetAlert $alert, NotificationService $notificationService): void
    {
        foreach ($users as $user) {
            $notificationService->sendNotification(
                $user,
                NotificationType::LOST_PET_ALERT_NEARBY,
                'Pet Perdido na sua Região',
                "{$alert->pet->name} está perdido próximo de você. Ajude a encontrá-lo!",
                ['alert_id' => $alert->id]
            );
        }
    }
}
