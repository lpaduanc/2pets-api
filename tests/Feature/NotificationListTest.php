<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Notifications\PetVetAccessRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bug: `GET /notifications` devolvia o `DatabaseNotification` cru — o payload útil
 * (`title`/`message`/`action_url`) ficava aninhado dentro da coluna `data`, e `type` vinha
 * como FQCN em vez do tipo semântico que `toArray()` já grava.
 */
class NotificationListTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_flattened_payload_with_semantic_type(): void
    {
        $tutor = User::factory()->tutor()->create();
        $vet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $tutor->notify(new PetVetAccessRequested(
            PetVetAccess::factoryCreatePending($vet, $pet),
            $pet,
            $vet,
            'CRMV/SP 45871',
        ));

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/notifications')->assertOk();

        $item = $response->json('data.0');

        $this->assertSame('pet_vet_access_requested', $item['type']);
        $this->assertSame("Pedido de acesso a {$pet->name}", $item['title']);
        $this->assertArrayHasKey('message', $item);
        $this->assertArrayHasKey('action_url', $item);
        $this->assertSame($pet->id, $item['data']['pet_id']);
        $this->assertArrayNotHasKey('type', $item['data']);
        $this->assertArrayNotHasKey('title', $item['data']);
    }

    /**
     * Regressão de BUG 1 (2026-09-13): `action_url` apontava para `/app/vets/solicitacoes`,
     * rota inexistente no frontend — a decisão de privacidade central do produto (tutor
     * aprova/recusa acesso ao prontuário) levava a um 404. A rota real é
     * `/tutor/vets/solicitacoes` (`2pets-app/src/router/routes.js`, name `vet-access-requests`).
     */
    public function test_pet_vet_access_requested_action_url_points_to_the_real_tutor_route(): void
    {
        $tutor = User::factory()->tutor()->create();
        $vet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $tutor->notify(new PetVetAccessRequested(
            PetVetAccess::factoryCreatePending($vet, $pet),
            $pet,
            $vet,
            'CRMV/SP 45871',
        ));

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/notifications')->assertOk();

        $response->assertJsonPath('data.0.action_url', '/tutor/vets/solicitacoes');
    }

    /**
     * Regressão de BUG 2 (2026-09-13): `InAppNotification::toArray()` gravava a mensagem em
     * `body`, mas `NotificationResource` só lia `message` — toda notificação disparada por
     * `SendAppointmentNotification`/`SendReviewNotification` chegava com `message` vazia.
     */
    public function test_in_app_notification_message_reaches_the_api_payload(): void
    {
        $tutor = User::factory()->tutor()->create();

        $tutor->notify(new InAppNotification(
            NotificationType::APPOINTMENT_CONFIRMED,
            'Consulta confirmada',
            'Sua consulta foi confirmada para amanhã às 10h.',
        ));

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/notifications')->assertOk();

        $response->assertJsonPath('data.0.message', 'Sua consulta foi confirmada para amanhã às 10h.');
    }

    /**
     * Compatibilidade com linhas legadas gravadas antes da correção do BUG 2: o payload em
     * `data` tinha `body`, nunca `message`. `NotificationResource` precisa continuar exibindo
     * a mensagem dessas notificações antigas, não só das novas.
     */
    public function test_legacy_notification_row_with_body_key_still_exposes_message(): void
    {
        $tutor = User::factory()->tutor()->create();

        $tutor->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => InAppNotification::class,
            'data' => [
                'type' => 'appointment_confirmed',
                'title' => 'Consulta confirmada',
                'body' => 'Mensagem legada gravada antes da correção do BUG 2.',
                'data' => [],
            ],
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/notifications')->assertOk();

        $response->assertJsonPath('data.0.message', 'Mensagem legada gravada antes da correção do BUG 2.');
    }

    /**
     * O painel do sino pede `per_page=5` e lê o contador de não lidas da MESMA resposta —
     * duas requisições separadas podiam discordar entre si.
     */
    public function test_index_honours_per_page_and_reports_unread_count(): void
    {
        $tutor = User::factory()->tutor()->create();

        foreach (range(1, 7) as $i) {
            $tutor->notify(new InAppNotification(NotificationType::NEW_MESSAGE, "Mensagem {$i}", 'Corpo'));
        }
        $tutor->notifications()->latest()->first()->markAsRead();

        Sanctum::actingAs($tutor);

        $this->getJson('/api/notifications?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.unread_count', 6);
    }

    public function test_unread_returns_only_the_count(): void
    {
        $tutor = User::factory()->tutor()->create();
        $other = User::factory()->tutor()->create();

        $tutor->notify(new InAppNotification(NotificationType::NEW_MESSAGE, 'A', 'Corpo'));
        $tutor->notify(new InAppNotification(NotificationType::NEW_MESSAGE, 'B', 'Corpo'));
        $other->notify(new InAppNotification(NotificationType::NEW_MESSAGE, 'C', 'Corpo'));

        Sanctum::actingAs($tutor);

        $this->getJson('/api/notifications/unread')
            ->assertOk()
            ->assertExactJson(['count' => 2]);
    }

    /**
     * A tela de preferências mostrava todo canal ligado sem nada gravado — inclusive SMS,
     * que nenhum tipo usa. O default exibido precisa ser o que de fato sai.
     */
    public function test_preferences_reflect_the_real_default_channels(): void
    {
        $tutor = User::factory()->tutor()->create();

        Sanctum::actingAs($tutor);

        $this->getJson('/api/notifications/preferences')
            ->assertOk()
            ->assertJsonPath('data.new_message.channels.push.enabled', true)
            ->assertJsonPath('data.new_message.channels.email.enabled', false)
            ->assertJsonPath('data.new_message.channels.sms.enabled', false)
            ->assertJsonPath('data.appointment_confirmed.channels.email.enabled', true);
    }

    public function test_preferences_reject_unknown_type_or_channel(): void
    {
        $tutor = User::factory()->tutor()->create();

        Sanctum::actingAs($tutor);

        $this->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['notification_type' => 'vaccine_reminders', 'channel' => 'email', 'enabled' => true],
            ],
        ])->assertUnprocessable();

        $this->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['notification_type' => 'new_message', 'channel' => 'pombo', 'enabled' => true],
            ],
        ])->assertUnprocessable();
    }
}
