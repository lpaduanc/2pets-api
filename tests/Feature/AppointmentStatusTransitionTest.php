<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG 2 + BUG 3 (E2E manual, 2026-09-13): `PUT /api/professional/appointments/{id}` gravava
 * qualquer `status` recebido sem validar a transição nem carimbar `confirmed_at`/`cancelled_at`.
 * Uma consulta `completed` podia voltar para `scheduled` sem erro, e `confirmed_at` continuava
 * `null` mesmo depois de confirmada.
 */
class AppointmentStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        Sanctum::actingAs($this->professional);
    }

    public function test_confirming_an_appointment_stamps_confirmed_at(): void
    {
        $appointment = $this->createAppointment('scheduled');

        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", [
            'status' => 'confirmed',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertNotNull($response->json('data.confirmed_at'));

        $this->assertNotNull($appointment->fresh()->confirmed_at);
    }

    public function test_cancelling_an_appointment_stamps_cancelled_at(): void
    {
        $appointment = $this->createAppointment('scheduled');

        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", [
            'status' => 'cancelled',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNotNull($response->json('data.cancelled_at'));
        $this->assertNotNull($appointment->fresh()->cancelled_at);
    }

    public function test_completed_appointment_cannot_go_back_to_scheduled(): void
    {
        $appointment = $this->createAppointment('scheduled');

        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'confirmed'])->assertOk();
        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'in_progress'])->assertOk();
        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();

        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'scheduled']);

        $response->assertStatus(422);
        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_scheduled_to_in_progress_is_rejected_it_must_pass_through_confirmed(): void
    {
        $appointment = $this->createAppointment('scheduled');

        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", [
            'status' => 'in_progress',
        ]);

        $response->assertStatus(422);
        $this->assertSame('scheduled', $appointment->fresh()->status);
    }

    public function test_cancelled_appointment_is_terminal(): void
    {
        $appointment = $this->createAppointment('cancelled');

        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(422);
    }

    public function test_full_happy_path_from_scheduled_to_completed_is_accepted(): void
    {
        $appointment = $this->createAppointment('scheduled');

        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'confirmed'])->assertOk();
        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'in_progress'])->assertOk();
        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed']);

        $response->assertOk()->assertJsonPath('data.status', 'completed');
    }

    private function createAppointment(string $status): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => $status,
        ]);
    }
}
