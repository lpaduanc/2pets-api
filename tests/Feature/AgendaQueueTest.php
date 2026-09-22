<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET professional/agenda/queue` — item 21 do backlog gap-simplesvet. "Próximos"/"Atendidos"
 * derivado de `Appointment::queueLabel()`, sem `queue_status` paralelo.
 */
class AgendaQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_splits_appointments_between_next_and_attended(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();
        $vet = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($organization, 'organization')->create(['user_id' => $owner->id]);
        OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $vet->id,
            'role' => OrganizationRole::VETERINARIAN->value,
        ]);
        $pet = Pet::factory()->create(['user_id' => $owner->id]);

        $this->appointment($vet, $organization, $owner, $pet, 'confirmed');
        $this->appointment($vet, $organization, $owner, $pet, 'completed');
        $this->appointment($vet, $organization, $owner, $pet, 'no_show');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/professional/agenda/queue?date='.now()->toDateString());

        $response->assertOk();
        $this->assertCount(1, $response->json('next'));
        $this->assertCount(2, $response->json('attended'));
    }

    public function test_checked_in_appointment_shows_as_waiting_in_next(): void
    {
        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $client->id]);
        $appointment = $this->appointment($vet, null, $client, $pet, 'confirmed');
        $appointment->update(['checked_in_at' => now()]);

        Sanctum::actingAs($vet);

        $response = $this->getJson('/api/professional/agenda/queue?date='.now()->toDateString());

        $response->assertOk()
            ->assertJsonPath('next.0.queue_label', 'waiting');
    }

    private function appointment(User $professional, ?Organization $organization, User $client, Pet $pet, string $status): Appointment
    {
        return Appointment::create([
            'professional_id' => $professional->id,
            'organization_id' => $organization?->id,
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => $status,
        ]);
    }
}
