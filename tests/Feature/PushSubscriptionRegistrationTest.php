<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `push_subscriptions` não tinha migration (achado pelo frontend integrando a Fase 4):
 * todo teste existente de push (`PushNotificationServiceTest`) mocka o service inteiro e
 * nunca toca a tabela de verdade — o que deixou a ausência da migration invisível. Este
 * teste NÃO mocka nada: bate em `POST /api/notifications/devices/register` e
 * `.../unregister` de verdade e confere o estado real da tabela depois.
 */
class PushSubscriptionRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_device_creates_a_subscription_row(): void
    {
        $tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($tutor);

        $response = $this->postJson('/api/notifications/devices/register', [
            'device_token' => 'device-token-one',
            'device_type' => 'android',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $tutor->id,
            'device_token' => 'device-token-one',
            'device_type' => 'android',
        ]);
    }

    public function test_registering_the_same_token_twice_updates_instead_of_duplicating(): void
    {
        $tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($tutor);

        $this->postJson('/api/notifications/devices/register', [
            'device_token' => 'device-token-repeat',
            'device_type' => 'android',
        ])->assertOk();

        $this->postJson('/api/notifications/devices/register', [
            'device_token' => 'device-token-repeat',
            'device_type' => 'android',
        ])->assertOk();

        $this->assertSame(
            1,
            PushSubscription::where('device_token', 'device-token-repeat')->count(),
            'O mesmo token registrado duas vezes deve atualizar a linha existente, não duplicar.'
        );
    }

    public function test_same_device_token_registered_by_a_different_user_reassigns_ownership(): void
    {
        $firstOwner = User::factory()->tutor()->create();
        $secondOwner = User::factory()->tutor()->create();

        Sanctum::actingAs($firstOwner);
        $this->postJson('/api/notifications/devices/register', [
            'device_token' => 'shared-device-token',
            'device_type' => 'ios',
        ])->assertOk();

        Sanctum::actingAs($secondOwner);
        $this->postJson('/api/notifications/devices/register', [
            'device_token' => 'shared-device-token',
            'device_type' => 'ios',
        ])->assertOk();

        $this->assertSame(
            1,
            PushSubscription::where('device_token', 'shared-device-token')->count(),
            'Aparelho trocando de dono deve resultar em uma única linha viva, associada ao novo usuário.'
        );
        $this->assertDatabaseHas('push_subscriptions', [
            'device_token' => 'shared-device-token',
            'user_id' => $secondOwner->id,
        ]);
    }

    public function test_unregistering_a_device_soft_deletes_it(): void
    {
        $tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($tutor);

        $this->postJson('/api/notifications/devices/register', [
            'device_token' => 'device-token-to-remove',
        ])->assertOk();

        $this->postJson('/api/notifications/devices/unregister', [
            'device_token' => 'device-token-to-remove',
        ])->assertOk();

        $this->assertSoftDeleted('push_subscriptions', [
            'device_token' => 'device-token-to-remove',
        ]);
    }

    public function test_find_subscriptions_for_only_returns_live_rows_of_the_correct_user(): void
    {
        $tutor = User::factory()->tutor()->create();
        $otherTutor = User::factory()->tutor()->create();

        PushSubscription::create([
            'user_id' => $tutor->id,
            'device_token' => 'live-token',
            'device_type' => 'web',
        ]);
        PushSubscription::create([
            'user_id' => $tutor->id,
            'device_token' => 'removed-token',
            'device_type' => 'web',
        ])->delete();
        PushSubscription::create([
            'user_id' => $otherTutor->id,
            'device_token' => 'other-user-token',
            'device_type' => 'web',
        ]);

        $liveTokens = PushSubscription::where('user_id', $tutor->id)
            ->pluck('device_token');

        $this->assertSame(['live-token'], $liveTokens->all());
    }
}
