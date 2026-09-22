<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Appointment;
use App\Models\NotificationPreference;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\Notification\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 4 do fluxo de agendamento: endpoints explícitos de confirmar/recusar
 * (`AppointmentConfirmationController`), com notificação ao tutor dos dois lados.
 */
class AppointmentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $professional;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $this->professional->id]);

        $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
        $service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta Geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150.00,
            'active' => true,
        ]);

        $this->appointment = Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $pet->id,
            'service_id' => $service->id,
            'appointment_date' => now()->addDay()->setTime(10, 0),
            'duration' => 30,
            'status' => 'pending',
            'booking_source' => 'client',
        ]);
    }

    public function test_professional_can_confirm_a_pending_appointment(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->professional);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $response->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('appointments', [
            'id' => $this->appointment->id,
            'status' => 'confirmed',
        ]);
        $this->assertNotNull($this->appointment->fresh()->confirmed_at);

        Notification::assertSentTo(
            $this->tutor,
            InAppNotification::class,
            fn (InAppNotification $notification): bool => $notification->toArray($this->tutor)['type'] === NotificationType::APPOINTMENT_CONFIRMED->value
        );
    }

    public function test_professional_can_reject_with_a_reason_and_the_tutor_sees_it(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->professional);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/reject", [
            'reason' => 'Agenda lotada neste horário',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('appointments', [
            'id' => $this->appointment->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Agenda lotada neste horário',
        ]);

        Notification::assertSentTo(
            $this->tutor,
            InAppNotification::class,
            function (InAppNotification $notification): bool {
                $array = $notification->toArray($this->tutor);

                return $array['type'] === NotificationType::APPOINTMENT_REJECTED->value
                    && str_contains($array['message'], 'Agenda lotada neste horário');
            }
        );
    }

    public function test_reject_without_a_reason_still_notifies_the_tutor(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->professional);

        $this->postJson("/api/professional/appointments/{$this->appointment->id}/reject")->assertOk();

        Notification::assertSentTo($this->tutor, InAppNotification::class);
    }

    public function test_a_professional_outside_the_appointment_cannot_confirm(): void
    {
        $outsider = User::factory()->professional()->create();
        Sanctum::actingAs($outsider);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $response->assertStatus(403);
        $this->assertDatabaseHas('appointments', ['id' => $this->appointment->id, 'status' => 'pending']);
    }

    public function test_a_professional_outside_the_appointment_cannot_reject(): void
    {
        $outsider = User::factory()->professional()->create();
        Sanctum::actingAs($outsider);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/reject");

        $response->assertStatus(403);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $this->appointment->update(['status' => 'completed']);
        Sanctum::actingAs($this->professional);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $response->assertStatus(422);
        $this->assertDatabaseHas('appointments', ['id' => $this->appointment->id, 'status' => 'completed']);
    }

    public function test_push_is_skipped_when_the_tutor_disabled_it_for_this_notification_type(): void
    {
        NotificationPreference::create([
            'user_id' => $this->tutor->id,
            'notification_type' => NotificationType::APPOINTMENT_CONFIRMED->value,
            'channel' => NotificationChannel::PUSH->value,
            'enabled' => false,
        ]);

        $this->mock(PushNotificationService::class, function ($mock): void {
            $mock->shouldReceive('send')->never();
        });

        Sanctum::actingAs($this->professional);
        $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm")->assertOk();

        // A notificação in-app continua sendo criada — só o push é que respeita a preferência.
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->tutor->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_push_is_sent_by_default_when_no_preference_was_set(): void
    {
        $this->mock(PushNotificationService::class, function ($mock): void {
            $mock->shouldReceive('send')->once();
        });

        Sanctum::actingAs($this->professional);
        $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm")->assertOk();
    }
}
