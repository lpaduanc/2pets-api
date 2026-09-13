<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bug: `AppointmentResource` read `appointment_date` (a timestamp whose time component is
     * always midnight — see `BookingService::createBooking`) instead of the dedicated
     * `appointment_time` column, so every appointment in the API response showed "00:00"
     * regardless of the real scheduled time.
     */
    public function test_appointment_time_reflects_the_scheduled_time_not_midnight(): void
    {
        $tutor = User::factory()->tutor()->create();
        $professional = User::factory()->professional()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $appointment = Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '14:30:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson("/api/appointments/{$appointment->id}");

        $response->assertOk()->assertJsonPath('data.appointment_time', '14:30');
    }
}
