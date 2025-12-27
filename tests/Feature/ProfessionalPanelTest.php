<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Pet;
use App\Models\Appointment;
use App\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class ProfessionalPanelTest extends TestCase
{
    use RefreshDatabase;

    protected $professional;
    protected $client;
    protected $pet;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a professional user
        $this->professional = User::factory()->create([
            'role' => 'professional',
            'email' => 'vet@example.com',
        ]);

        // Create professional profile
        Professional::create([
            'user_id' => $this->professional->id,
            'business_name' => 'Vet Clinic',
            'type' => 'veterinarian',
            'description' => 'General Vet',
            'phone' => '1234567890'
        ]);

        // Create a client and pet
        $this->client = User::factory()->create(['role' => 'tutor']);
        $this->pet = Pet::factory()->create(['user_id' => $this->client->id]);

        // Authenticate as professional
        Sanctum::actingAs($this->professional);
    }

    public function test_can_list_clients()
    {
        $response = $this->getJson('/api/professional/clients');
        $response->assertStatus(200);
    }

    public function test_can_create_appointment()
    {
        $data = [
            'client_id' => $this->client->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'consultation',
            'status' => 'scheduled',
            'reason' => 'Checkup'
        ];

        $response = $this->postJson('/api/professional/appointments', $data);
        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', ['reason' => 'Checkup']);
    }

    public function test_can_list_appointments()
    {
        Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->client->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => 'consultation',
            'status' => 'scheduled'
        ]);

        $response = $this->getJson('/api/professional/appointments');
        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_can_create_medical_record()
    {
        $response = $this->postJson('/api/professional/medical-records', [
            'pet_id' => $this->pet->id,
            'record_date' => now()->toDateString(),
            'diagnosis' => 'Healthy',
            'subjective' => 'Normal',
            'objective' => 'Normal',
            'assessment' => 'Normal',
            'plan' => 'None'
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_vaccination()
    {
        $response = $this->postJson('/api/professional/vaccinations', [
            'pet_id' => $this->pet->id,
            'vaccine_name' => 'Rabies',
            'application_date' => now()->toDateString(),
            'next_dose_date' => now()->addYear()->toDateString(),
            'dose_number' => 1
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_prescription()
    {
        $response = $this->postJson('/api/professional/prescriptions', [
            'pet_id' => $this->pet->id,
            'prescription_date' => now()->toDateString(),
            'medications' => [['name' => 'Med A', 'dosage' => '10mg', 'frequency' => 'Daily', 'duration' => '7 days']]
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_hospitalization()
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Observation',
            'status' => 'active'
        ]);

        $response->assertStatus(201);
    }
    public function test_can_create_surgery()
    {
        $response = $this->postJson('/api/professional/surgeries', [
            'pet_id' => $this->pet->id,
            'surgery_date' => now()->toDateString(),
            'surgery_type' => 'Spay',
            'procedure_description' => 'Spay procedure',
            'status' => 'scheduled'
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_invoice()
    {
        // Need an appointment for invoice usually?
        $appointment = Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->client->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => 'consultation',
            'status' => 'completed'
        ]);

        $response = $this->postJson('/api/professional/invoices', [
            'client_id' => $this->client->id,
            'appointment_id' => $appointment->id,
            'invoice_number' => 'INV-001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 100, 'total' => 100]],
            'subtotal' => 100,
            'total' => 100,
            'status' => 'pending'
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_service()
    {
        $response = $this->postJson('/api/professional/services', [
            'name' => 'Consultation',
            'category' => 'consultation',
            'price' => 100,
            'duration' => 30,
            'active' => true
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_inventory_item()
    {
        $response = $this->postJson('/api/professional/inventory', [
            'item_name' => 'Bandage',
            'category' => 'supply',
            'quantity' => 100,
            'unit' => 'roll',
            'min_quantity' => 10,
            'cost_price' => 5,
            'selling_price' => 10
        ]);

        $response->assertStatus(201);
    }
}
