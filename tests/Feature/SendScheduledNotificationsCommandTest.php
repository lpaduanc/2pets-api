<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `scheduled_notifications` não tinha migration — achado auditando "model sem tabela"
 * (mesma classe de bug de `push_subscriptions`): o comando agendado `notifications:send`
 * explodia com `SQLSTATE[42P01]` em `sendQueuedNotifications()`, e como isso acontece
 * DEPOIS de `sendAppointmentReminders()` sem `try/catch`, o comando inteiro falhava —
 * os lembretes de consulta (24h/2h) também paravam de sair. Este teste roda o comando
 * DE VERDADE (sem mock de `ScheduledNotification` nem de `NotificationService`) contra a
 * tabela real.
 */
class SendScheduledNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sends_due_queued_notification_and_marks_it_sent(): void
    {
        $tutor = User::factory()->tutor()->create();

        $due = ScheduledNotification::create([
            'user_id' => $tutor->id,
            'notification_type' => NotificationType::VACCINATION_DUE->value,
            'data' => ['title' => 'Vacina vencendo', 'body' => 'A V10 do Rex vence amanhã.'],
            'scheduled_for' => now()->subMinute(),
            'sent' => false,
        ]);

        $this->artisan('notifications:send')->assertSuccessful();

        $this->assertDatabaseHas('scheduled_notifications', [
            'id' => $due->id,
            'sent' => true,
        ]);
        $this->assertNotNull($due->fresh()->sent_at);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $tutor->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_command_does_not_send_a_notification_scheduled_for_the_future(): void
    {
        $tutor = User::factory()->tutor()->create();

        $future = ScheduledNotification::create([
            'user_id' => $tutor->id,
            'notification_type' => NotificationType::VACCINATION_DUE->value,
            'data' => ['title' => 'Vacina vencendo', 'body' => 'Ainda não é hora.'],
            'scheduled_for' => now()->addDay(),
            'sent' => false,
        ]);

        $this->artisan('notifications:send')->assertSuccessful();

        $this->assertDatabaseHas('scheduled_notifications', [
            'id' => $future->id,
            'sent' => false,
        ]);
    }

    public function test_command_does_not_resend_an_already_sent_notification(): void
    {
        $tutor = User::factory()->tutor()->create();

        $alreadySent = ScheduledNotification::create([
            'user_id' => $tutor->id,
            'notification_type' => NotificationType::VACCINATION_DUE->value,
            'data' => ['title' => 'Vacina vencendo', 'body' => 'Já enviada antes.'],
            'scheduled_for' => now()->subDay(),
            'sent' => true,
            'sent_at' => now()->subHour(),
        ]);

        $this->artisan('notifications:send')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(
            $alreadySent->sent_at->toDateTimeString(),
            $alreadySent->fresh()->sent_at->toDateTimeString(),
            'O comando não deveria reprocessar uma notificação já marcada como enviada.'
        );
    }
}
