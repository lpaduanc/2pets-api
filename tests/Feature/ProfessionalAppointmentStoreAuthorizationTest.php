<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §E: com a
 * consulta passando a valer acesso de escrita clínica, `POST professional/appointments`
 * precisa garantir que `pet_id` pertence a `client_id` e que `client_id` já é cliente deste
 * profissional — senão criar o agendamento vira porta de escrita em prontuário de qualquer
 * pet/tutor.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class ProfessionalAppointmentStoreAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        Sanctum::actingAs($this->professional);
    }

    public function test_rejects_pet_that_does_not_belong_to_the_given_client(): void
    {
        $client = $this->grantedClient();
        $someoneElsesPet = Pet::factory()->create(['user_id' => User::factory()->tutor()->create()->id]);

        $response = $this->postJson('/api/professional/appointments', $this->payload($client->id, $someoneElsesPet->id));

        $response->assertStatus(422)->assertJsonValidationErrors('pet_id');
    }

    public function test_rejects_client_that_is_not_yet_this_professionals_client(): void
    {
        $stranger = User::factory()->tutor()->create();
        $strangersPet = Pet::factory()->create(['user_id' => $stranger->id]);

        $response = $this->postJson('/api/professional/appointments', $this->payload($stranger->id, $strangersPet->id));

        $response->assertStatus(422)->assertJsonValidationErrors('client_id');
        $this->assertDatabaseMissing('appointments', ['pet_id' => $strangersPet->id]);
    }

    public function test_accepts_client_reached_via_active_pet_vet_access_and_stamps_booking_source(): void
    {
        $client = $this->grantedClient();
        $pet = $client->pets()->first();

        $response = $this->postJson('/api/professional/appointments', $this->payload($client->id, $pet->id));

        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', [
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'booking_source' => 'professional',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $clientId, int $petId): array
    {
        return [
            'client_id' => $clientId,
            'pet_id' => $petId,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'consultation',
            'reason' => 'Checkup',
        ];
    }

    private function grantedClient(): User
    {
        $client = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $client->id]);

        PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $client->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        return $client;
    }
}
