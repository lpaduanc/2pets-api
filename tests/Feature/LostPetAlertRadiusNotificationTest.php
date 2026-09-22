<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\LostPetAlert;
use App\Models\NotificationPreference;
use App\Models\Pet;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\Notification\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /api/pet-card/{petId}/mark-lost` — o unico caminho de producao que o
 * app usa para acionar o alerta de pet perdido (`PetCardPage.vue`,
 * `markAsLost()`).
 *
 * Regra de negocio coberta aqui: TODO tutor e TODO profissional com endereco
 * cadastrado dentro do raio do alerta recebem a notificacao — nao so tutores,
 * nao so quem estiver a 5 km.
 */
class LostPetAlertRadiusNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** Praca da Se, Sao Paulo — origem de todos os cenarios abaixo. */
    private const ORIGIN_LAT = -23.5505;

    private const ORIGIN_LNG = -46.6333;

    public function test_marking_a_pet_as_lost_creates_an_active_alert_at_the_tutor_location(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Thor']);

        Sanctum::actingAs($tutor);

        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", [
            'message' => 'Fugiu pelo portao da frente.',
        ])->assertOk();

        $this->assertTrue($pet->fresh()->is_lost);

        $alert = LostPetAlert::where('pet_id', $pet->id)->first();

        $this->assertNotNull($alert, 'mark-lost deveria criar um LostPetAlert.');
        $this->assertSame('active', $alert->status);
        $this->assertEqualsWithDelta(self::ORIGIN_LAT, (float) $alert->last_seen_latitude, 0.0001);
        $this->assertEqualsWithDelta(self::ORIGIN_LNG, (float) $alert->last_seen_longitude, 0.0001);
    }

    /**
     * O app chama `api.post('/pet-card/{id}/mark-lost')` sem corpo nenhum e
     * mostra o toast de sucesso mesmo quando a requisicao falha. Se o corpo
     * for obrigatorio, o tutor ve "alerta enviado" e ninguem e notificado.
     */
    public function test_marking_a_pet_as_lost_works_without_a_request_body(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        Sanctum::actingAs($tutor);

        $this->postJson("/api/pet-card/{$pet->id}/mark-lost")->assertOk();

        $this->assertDatabaseHas('lost_pet_alerts', ['pet_id' => $pet->id, 'status' => 'active']);
    }

    public function test_every_tutor_and_professional_within_30km_is_notified(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $nearbyTutor = $this->userAtKmNorth('tutor', 2);
        $edgeTutor = $this->userAtKmNorth('tutor', 28);
        $nearbyVet = $this->userAtKmNorth('professional', 5);
        $edgeVet = $this->userAtKmNorth('professional', 29);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();

        foreach ([$nearbyTutor, $edgeTutor, $nearbyVet, $edgeVet] as $recipient) {
            $this->assertNotified($recipient);
        }
    }

    public function test_users_beyond_the_30km_radius_are_not_notified(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $farTutor = $this->userAtKmNorth('tutor', 45);
        $farVet = $this->userAtKmNorth('professional', 60);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();

        $this->assertNotNotified($farTutor);
        $this->assertNotNotified($farVet);
    }

    public function test_the_owner_does_not_receive_their_own_alert(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();

        $this->assertNotNotified($tutor);
    }

    public function test_a_tutor_without_a_registered_address_cannot_trigger_a_silent_alert(): void
    {
        $tutor = User::factory()->tutor()->create(['latitude' => null, 'longitude' => null]);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $neighbour = $this->userAtKmNorth('tutor', 1);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();

        $this->assertTrue($pet->fresh()->is_lost, 'O pet deve ficar marcado como perdido mesmo sem coordenada.');
        $this->assertNotNotified($neighbour);
    }

    /**
     * O tutor pode estar longe de casa quando percebe o sumico — o app manda
     * a coordenada do aparelho e o raio deve girar em volta dela, nao em
     * volta do endereco de cadastro.
     */
    public function test_explicit_coordinates_from_the_request_win_over_the_tutor_address(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $nearHome = $this->userAtKmNorth('tutor', 3);
        $nearSighting = $this->userAtKmNorth('tutor', 100);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", [
            'message' => 'Sumiu na viagem.',
            'last_seen_latitude' => $this->latAtKmNorth(100),
            'last_seen_longitude' => self::ORIGIN_LNG,
        ])->assertOk();

        $this->assertNotified($nearSighting);
        $this->assertNotNotified($nearHome);
    }

    public function test_marking_the_pet_as_found_closes_the_active_alert(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();
        $this->postJson("/api/pet-card/{$pet->id}/mark-found")->assertOk();

        $this->assertFalse($pet->fresh()->is_lost);
        $this->assertDatabaseMissing('lost_pet_alerts', ['pet_id' => $pet->id, 'status' => 'active']);
    }

    /**
     * Uma notificacao sem destino e so um aviso: o menu "Pets Perdidos" existe
     * com prefixo proprio em cada layout, e o `action_url` tem que apontar para
     * o do destinatario — senao o guard do router barra o profissional.
     */
    public function test_each_recipient_gets_the_deep_link_of_their_own_layout(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $neighbourTutor = $this->userAtKmNorth('tutor', 2);
        $vet = $this->userAtKmNorth('professional', 6);
        $clinic = $this->userAtKmNorth('company', 9);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();

        $this->assertSame('/tutor/pets-perdidos', $this->actionUrlFor($neighbourTutor));
        $this->assertSame('/professional/pets-perdidos', $this->actionUrlFor($vet));
        $this->assertSame('/company/pets-perdidos', $this->actionUrlFor($clinic));
    }

    /**
     * O push e o unico canal default de `LOST_PET_ALERT_NEARBY`, e a
     * notificacao in-app e gravada sempre — mas desligar o push nas
     * preferencias tem que realmente desligar o push.
     */
    public function test_a_user_who_turned_the_push_off_still_gets_the_in_app_alert_but_no_push(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $optedOut = $this->userAtKmNorth('tutor', 4);
        NotificationPreference::create([
            'user_id' => $optedOut->id,
            'notification_type' => NotificationType::LOST_PET_ALERT_NEARBY->value,
            'channel' => 'push',
            'enabled' => false,
        ]);

        $push = $this->spyPushService();

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();

        $this->assertNotified($optedOut);
        $this->assertNotContains($optedOut->id, $push->pushedUserIds);
    }

    public function test_a_user_who_left_preferences_untouched_gets_the_push(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $neighbour = $this->userAtKmNorth('professional', 4);

        $push = $this->spyPushService();

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost", ['message' => 'Sumiu ontem.'])->assertOk();

        $this->assertContains($neighbour->id, $push->pushedUserIds);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Substitui o `PushNotificationService` por um dublê que so anota quem
     * receberia o push — o real e um no-op silencioso sem FCM configurado, o
     * que tornaria o assert acima verde por engano.
     */
    private function spyPushService(): PushNotificationService
    {
        $spy = new class extends PushNotificationService
        {
            /** @var list<int> */
            public array $pushedUserIds = [];

            public function send(User $user, string $title, string $body, array $data = []): void
            {
                $this->pushedUserIds[] = $user->id;
            }
        };

        $this->app->instance(PushNotificationService::class, $spy);

        return $spy;
    }

    /** 1 grau de latitude ~ 110.57 km — suficiente para posicionar cenarios. */
    private function latAtKmNorth(float $km): float
    {
        return self::ORIGIN_LAT + ($km / 110.57);
    }

    private function userAtKmNorth(string $state, float $km): User
    {
        return User::factory()->{$state}()->create([
            'latitude' => $this->latAtKmNorth($km),
            'longitude' => self::ORIGIN_LNG,
        ]);
    }

    private function assertNotified(User $user): void
    {
        $this->assertTrue(
            $this->lostPetNotificationCount($user) > 0,
            "Usuario {$user->id} ({$user->role}) deveria ter recebido o alerta de pet perdido."
        );
    }

    private function assertNotNotified(User $user): void
    {
        $this->assertSame(
            0,
            $this->lostPetNotificationCount($user),
            "Usuario {$user->id} ({$user->role}) NAO deveria ter recebido o alerta de pet perdido."
        );
    }

    private function actionUrlFor(User $user): ?string
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('data->type', NotificationType::LOST_PET_ALERT_NEARBY->value)
            ->value(DB::raw("data->>'action_url'"));
    }

    private function lostPetNotificationCount(User $user): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', InAppNotification::class)
            ->where('data->type', NotificationType::LOST_PET_ALERT_NEARBY->value)
            ->count();
    }
}
