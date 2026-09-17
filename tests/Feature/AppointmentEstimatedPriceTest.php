<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §3/§13.2: quando
 * `AppointmentController::store` recebe `service_id` sem `price`, o valor do serviço entra
 * como estimativa pré-atendimento (caminho LEGADO, deprecado não removido). O caminho novo
 * (`services[]`, §13.2/§13.7) soma vários serviços e grava a pivô `appointment_services`
 * com preço SNAPSHOT (invariante 12).
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class AppointmentEstimatedPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $client;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->client = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->client->id]);

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

        Sanctum::actingAs($this->professional);
    }

    public function test_price_is_filled_from_the_service_catalog_when_omitted(): void
    {
        $service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 180,
            'active' => true,
        ]);

        $response = $this->postJson('/api/professional/appointments', $this->payload($service->id));

        $response->assertStatus(201)->assertJsonPath('data.price', 180.0);
    }

    public function test_explicit_price_is_never_overridden_by_the_service_catalog(): void
    {
        $service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 180,
            'active' => true,
        ]);

        $response = $this->postJson('/api/professional/appointments', [
            ...$this->payload($service->id),
            'price' => 95,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.price', 95.0);
    }

    public function test_cannot_use_a_service_from_another_professionals_catalog(): void
    {
        $otherProfessional = User::factory()->professional()->create();
        $service = Service::create([
            'professional_id' => $otherProfessional->id,
            'name' => 'Consulta geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 180,
            'active' => true,
        ]);

        $response = $this->postJson('/api/professional/appointments', $this->payload($service->id));

        $response->assertStatus(422)->assertJsonValidationErrors('service_id');
    }

    /** Contrato §13.2: `services[]` soma vários serviços num único agendamento. */
    public function test_price_is_the_sum_of_multiple_services_when_services_array_is_sent(): void
    {
        $consultation = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150,
            'active' => true,
        ]);
        $vaccine = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Vacina V10',
            'category' => 'vaccination',
            'duration' => 15,
            'price' => 120,
            'active' => true,
        ]);

        $response = $this->postJson('/api/professional/appointments', [
            ...$this->payload($consultation->id),
            'services' => [
                ['service_id' => $consultation->id],
                ['service_id' => $vaccine->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.price', 270.0)
            ->assertJsonCount(2, 'data.services');
    }

    /** Invariante 12: preço é snapshot — mudar `services.price` depois não altera o já agendado. */
    public function test_services_array_snapshots_the_price_and_ignores_later_catalog_changes(): void
    {
        $service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150,
            'active' => true,
        ]);

        $response = $this->postJson('/api/professional/appointments', [
            ...$this->payload($service->id),
            'services' => [['service_id' => $service->id]],
        ]);
        $response->assertStatus(201)->assertJsonPath('data.price', 150.0);

        $service->update(['price' => 999]);

        $appointmentId = $response->json('data.id');
        $this->getJson("/api/professional/appointments/{$appointmentId}")
            ->assertOk()
            ->assertJsonPath('data.services.0.unit_price', 150.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $serviceId): array
    {
        return [
            'client_id' => $this->client->id,
            'pet_id' => $this->pet->id,
            'service_id' => $serviceId,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'consultation',
            'reason' => 'Checkup',
        ];
    }
}
