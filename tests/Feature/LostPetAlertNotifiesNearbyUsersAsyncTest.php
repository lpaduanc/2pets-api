<?php

namespace Tests\Feature;

use App\Jobs\NotifyNearbyUsersOfLostPetAlert;
use App\Models\Pet;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\LostPet\LostPetAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 6 do plano de otimizacao — antes desta fase,
 * `LostPetAlertService::notifyNearbyUsers()` varria `users` inteira com
 * Haversine cru em `HAVING` (SQL invalido no Postgres, ver relato da fase:
 * a query literal lancava `QueryException` a cada `createAlert()`) e
 * notificava de forma sincrona, dentro da `DB::transaction()` que criava o
 * alerta. Agora o disparo e assincrono via `NotifyNearbyUsersOfLostPetAlert`.
 */
class LostPetAlertNotifiesNearbyUsersAsyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_alert_dispatches_the_notification_job_instead_of_notifying_synchronously(): void
    {
        Queue::fake();

        $owner = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $owner->id]);

        $alert = app(LostPetAlertService::class)->createAlert($pet, [
            'description' => 'Visto pela última vez perto do parque.',
            'last_seen_location' => 'Parque Ibirapuera, São Paulo',
            'last_seen_latitude' => -23.5874,
            'last_seen_longitude' => -46.6576,
            'last_seen_at' => now(),
            'contact_info' => ['phone' => '11999999999'],
        ]);

        Queue::assertPushed(
            NotifyNearbyUsersOfLostPetAlert::class,
            fn (NotifyNearbyUsersOfLostPetAlert $job) => $this->jobTargetsAlert($job, $alert->id)
        );
    }

    public function test_creating_an_alert_without_coordinates_does_not_dispatch_the_job(): void
    {
        Queue::fake();

        $owner = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $owner->id]);

        app(LostPetAlertService::class)->createAlert($pet, [
            'description' => 'Sem localizacao precisa.',
            'last_seen_location' => 'Bairro desconhecido',
            'last_seen_at' => now(),
            'contact_info' => ['phone' => '11999999999'],
        ]);

        Queue::assertNotPushed(NotifyNearbyUsersOfLostPetAlert::class);
    }

    /**
     * Roda o job de verdade (fila `sync` em `phpunit.xml`) para provar que o
     * `ST_DWithin` do job filtra corretamente por raio: usuario dentro do
     * raio recebe notificacao, usuario em outra cidade nao recebe.
     */
    public function test_the_job_notifies_only_users_within_the_alert_radius(): void
    {
        $owner = User::factory()->tutor()->create([
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);
        $pet = Pet::factory()->create(['user_id' => $owner->id]);

        $nearbyUser = User::factory()->tutor()->create([
            'latitude' => -23.5510,
            'longitude' => -46.6330,
        ]);

        $farUser = User::factory()->tutor()->create([
            'latitude' => -22.9099,
            'longitude' => -47.0626,
        ]);

        app(LostPetAlertService::class)->createAlert($pet, [
            'description' => 'Visto pela última vez na praça.',
            'last_seen_location' => 'Praça da Sé, São Paulo',
            'last_seen_latitude' => -23.5505,
            'last_seen_longitude' => -46.6333,
            'last_seen_at' => now(),
            'contact_info' => ['phone' => '11999999999'],
            'alert_radius_km' => 5,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $nearbyUser->id,
            'type' => InAppNotification::class,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $farUser->id,
            'type' => InAppNotification::class,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $owner->id,
            'type' => InAppNotification::class,
        ]);
    }

    private function jobTargetsAlert(NotifyNearbyUsersOfLostPetAlert $job, int $alertId): bool
    {
        $reflection = new \ReflectionProperty($job, 'lostPetAlertId');
        $reflection->setAccessible(true);

        return $reflection->getValue($job) === $alertId;
    }
}
