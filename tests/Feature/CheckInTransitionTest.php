<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item 21 do backlog gap-simplesvet, regra 3: check-in não pula estado. Só é possível marcar
 * `checked_in_at` em um agendamento `confirmed`/`scheduled` do dia corrente.
 */
class CheckInTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function appointment(User $professional, array $overrides = []): Appointment
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->for($tutor)->create();

        return Appointment::create(array_merge([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->startOfDay(),
            'appointment_time' => now()->format('H:i'),
            'duration' => 30,
            'type' => 'consultation',
            'status' => AppointmentStatus::CONFIRMED->value,
        ], $overrides));
    }

    public function test_check_in_succeeds_for_confirmed_appointment_today(): void
    {
        $professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $professional->id]);
        $appointment = $this->appointment($professional);

        Sanctum::actingAs($professional);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/check-in");

        $response->assertOk();
        $this->assertNotNull($appointment->fresh()->checked_in_at);
    }

    public function test_check_in_rejects_appointment_from_another_day(): void
    {
        $professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $professional->id]);
        $appointment = $this->appointment($professional, [
            'appointment_date' => now()->addDay()->startOfDay(),
        ]);

        Sanctum::actingAs($professional);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/check-in");

        $response->assertStatus(422);
        $this->assertNull($appointment->fresh()->checked_in_at);
    }

    public function test_check_in_rejects_completed_appointment(): void
    {
        $professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $professional->id]);
        $appointment = $this->appointment($professional, ['status' => AppointmentStatus::COMPLETED->value]);

        Sanctum::actingAs($professional);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/check-in");

        $response->assertStatus(422);
    }
}
