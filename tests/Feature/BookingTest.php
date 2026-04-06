<?php

namespace Tests\Feature;

use App\Events\AppointmentBooked;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\Availability;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private User $professionalUser;
    private Pet $pet;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();

        $this->professionalUser = User::factory()->professional()->create();

        Professional::factory()->create([
            'user_id' => $this->professionalUser->id,
        ]);

        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $this->service = Service::create([
            'professional_id' => $this->professionalUser->id,
            'name' => 'Consulta Geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150.00,
            'active' => true,
        ]);

        // Create availability for the professional on the booking day.
        // We use the day of week for "tomorrow" to guarantee slot availability.
        $tomorrow = now()->addDay();
        Availability::create([
            'professional_id' => $this->professionalUser->id,
            'day_of_week' => $tomorrow->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // Booking creation
    // ---------------------------------------------------------------

    public function test_authenticated_user_can_book_appointment(): void
    {
        Event::fake();
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();
        $appointmentDate = $tomorrow->copy()->setTime(10, 0)->toDateTimeString();

        $response = $this->postJson('/api/public/booking', [
            'professional_id' => $this->professionalUser->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $appointmentDate,
            'notes' => 'Checkup anual',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'professional_id', 'client_id', 'service_id', 'status'],
            ]);

        $this->assertDatabaseHas('appointments', [
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'service_id' => $this->service->id,
            'status' => 'pending',
        ]);
    }

    public function test_unauthenticated_user_cannot_book(): void
    {
        $tomorrow = now()->addDay();
        $appointmentDate = $tomorrow->copy()->setTime(10, 0)->toDateTimeString();

        $response = $this->postJson('/api/public/booking', [
            'professional_id' => $this->professionalUser->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $appointmentDate,
        ]);

        $response->assertStatus(401);
    }

    public function test_cannot_book_in_the_past(): void
    {
        Sanctum::actingAs($this->tutor);

        $pastDate = now()->subDays(3)->setTime(10, 0)->toDateTimeString();

        $response = $this->postJson('/api/public/booking', [
            'professional_id' => $this->professionalUser->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $pastDate,
        ]);

        // The controller validation requires after_or_equal:today, so this should
        // be rejected either by validation (422) or by the service layer.
        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Cancellation
    // ---------------------------------------------------------------

    public function test_user_can_cancel_booking(): void
    {
        Event::fake();
        Sanctum::actingAs($this->tutor);

        $appointment = Appointment::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'service_id' => $this->service->id,
            'appointment_date' => now()->addDay()->setTime(10, 0),
            'duration' => 30,
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/public/booking/{$appointment->id}/cancel", [
            'reason' => 'Imprevisto pessoal',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Booking cancelled successfully']);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'cancelled',
        ]);
    }

    // ---------------------------------------------------------------
    // Rescheduling
    // ---------------------------------------------------------------

    public function test_user_can_reschedule_booking(): void
    {
        Event::fake();
        Sanctum::actingAs($this->tutor);

        $appointment = Appointment::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'service_id' => $this->service->id,
            'appointment_date' => now()->addDay()->setTime(10, 0),
            'duration' => 30,
            'status' => 'pending',
        ]);

        $newDate = now()->addDays(3)->setTime(14, 0)->toDateTimeString();

        $response = $this->postJson("/api/public/booking/{$appointment->id}/reschedule", [
            'appointment_date' => $newDate,
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Booking rescheduled successfully']);
    }

    public function test_cannot_reschedule_cancelled_booking(): void
    {
        Sanctum::actingAs($this->tutor);

        $appointment = Appointment::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'service_id' => $this->service->id,
            'appointment_date' => now()->addDay()->setTime(10, 0),
            'duration' => 30,
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Test cancel',
        ]);

        $newDate = now()->addDays(5)->setTime(14, 0)->toDateTimeString();

        $response = $this->postJson("/api/public/booking/{$appointment->id}/reschedule", [
            'appointment_date' => $newDate,
        ]);

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------

    public function test_events_are_dispatched_on_booking(): void
    {
        Event::fake([AppointmentBooked::class]);
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();
        $appointmentDate = $tomorrow->copy()->setTime(10, 0)->toDateTimeString();

        $this->postJson('/api/public/booking', [
            'professional_id' => $this->professionalUser->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $appointmentDate,
        ]);

        Event::assertDispatched(AppointmentBooked::class);
    }

    public function test_events_are_dispatched_on_cancellation(): void
    {
        Event::fake([AppointmentCancelled::class]);
        Sanctum::actingAs($this->tutor);

        $appointment = Appointment::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'service_id' => $this->service->id,
            'appointment_date' => now()->addDay()->setTime(10, 0),
            'duration' => 30,
            'status' => 'pending',
        ]);

        $this->postJson("/api/public/booking/{$appointment->id}/cancel", [
            'reason' => 'Emergencia',
        ]);

        Event::assertDispatched(AppointmentCancelled::class);
    }
}
