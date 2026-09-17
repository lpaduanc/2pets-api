<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfessionalPanelTest extends TestCase
{
    use RefreshDatabase;

    protected $professional;

    protected $client;

    protected $pet;

    protected function setUp(): void
    {
        parent::setUp();

        // The `professional()` / `tutor()` states also assign the Spatie role the endpoints
        // authorize against — the `users.role` column alone is not enough (see PetController).
        $this->professional = User::factory()->professional()->create([
            'email' => 'vet@example.com',
            'phone' => '1234567890',
        ]);

        Professional::factory()->veterinarian()->create([
            'user_id' => $this->professional->id,
            'business_name' => 'Vet Clinic',
            'description' => 'General Vet',
        ]);

        // Create a client and pet
        $this->client = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->client->id]);

        // The tutor grants WRITE access to the vet. Without this row the vet cannot write
        // medical data for the pet (AuthorizesPetAccess::resolvePetForWrite → 403), which is
        // the core privacy rule of the product — see test_cannot_write_medical_data_without_grant.
        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $this->client->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

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
            'reason' => 'Checkup',
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
            'status' => 'scheduled',
        ]);

        // Counts the rows, not the envelope keys: the listing is paginated, so the
        // root now carries `data`, `links` and `meta`.
        $response = $this->getJson('/api/professional/appointments');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
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
            'plan' => 'None',
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
            'dose_number' => 1,
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_prescription()
    {
        $response = $this->postJson('/api/professional/prescriptions', [
            'pet_id' => $this->pet->id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'remote_orientation',
            'items' => [['commercial_name' => 'Med A', 'dose_value' => 10, 'dose_unit' => 'mg', 'frequency' => 'sid', 'duration_text' => '7 days']],
        ]);

        $response->assertStatus(201);
    }

    public function test_can_create_hospitalization()
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Observation',
            'status' => 'active',
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
            'status' => 'scheduled',
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
            'status' => 'completed',
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
            'status' => 'pending',
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
            'active' => true,
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
            'selling_price' => 10,
        ]);

        $response->assertStatus(201);
    }

    /**
     * Locks the core privacy rule (CLAUDE.md §3): a vet writes medical data only for a pet the
     * tutor explicitly authorized. Every other test in this class passes *because* setUp grants
     * that access — this one proves the grant is what does the work, not a hole in the guard.
     */
    public function test_cannot_write_medical_data_without_grant(): void
    {
        $strangerPet = Pet::factory()->create([
            'user_id' => User::factory()->tutor()->create()->id,
        ]);

        $this->postJson('/api/professional/medical-records', [
            'pet_id' => $strangerPet->id,
            'record_date' => now()->toDateString(),
            'diagnosis' => 'Healthy',
            'subjective' => 'Normal',
            'objective' => 'Normal',
            'assessment' => 'Normal',
            'plan' => 'None',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('medical_records', ['pet_id' => $strangerPet->id]);
    }

    /**
     * A revoked grant must stop working immediately — revogação é direito do tutor a qualquer
     * momento, e cache/rota nenhuma pode sobreviver a ela.
     */
    public function test_revoked_grant_blocks_medical_write(): void
    {
        PetVetAccess::query()
            ->where('pet_id', $this->pet->id)
            ->where('veterinarian_id', $this->professional->id)
            ->update([
                'is_active' => false,
                'revoked_at' => now(),
                'revoked_by' => $this->client->id,
            ]);

        $this->postJson('/api/professional/medical-records', [
            'pet_id' => $this->pet->id,
            'record_date' => now()->toDateString(),
            'diagnosis' => 'Healthy',
            'subjective' => 'Normal',
            'objective' => 'Normal',
            'assessment' => 'Normal',
            'plan' => 'None',
        ])->assertStatus(403);
    }
}
