<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2 do fluxo de agendamento: agenda por estabelecimento, modo agregado ("qualquer
 * profissional disponível") e agendamento resolvendo/validando o profissional da equipe.
 */
class AggregatedBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $owner;

    private Organization $organization;

    private User $vetOne;

    private User $vetTwo;

    private Service $service;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $this->owner = User::factory()->professional()->create();
        $this->organization = Organization::factory()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
        ]);

        $this->vetOne = User::factory()->professional()->create();
        $this->vetTwo = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $this->vetOne->id]);
        Professional::factory()->create(['user_id' => $this->vetTwo->id]);

        OrganizationMember::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $this->vetOne->id]);
        OrganizationMember::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $this->vetTwo->id]);

        $this->service = Service::create([
            'professional_id' => $this->vetOne->id,
            'organization_id' => $this->organization->id,
            'name' => 'Consulta Geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150.00,
            'active' => true,
        ]);

        $tomorrow = now()->addDay();

        foreach ([$this->vetOne, $this->vetTwo] as $vet) {
            Availability::create([
                'professional_id' => $vet->id,
                'organization_id' => $this->organization->id,
                'day_of_week' => $tomorrow->dayOfWeek,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'slot_duration' => 30,
                'buffer_time' => 0,
                'is_active' => true,
            ]);
        }
    }

    public function test_individual_availability_is_scoped_to_the_establishment(): void
    {
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $this->vetOne->id,
            'organization_id' => $this->organization->id,
            'date' => $tomorrow->toDateString(),
        ]));

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_aggregated_availability_returns_slots_from_the_whole_team(): void
    {
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'organization_id' => $this->organization->id,
            'date' => $tomorrow->toDateString(),
        ]));

        $response->assertOk();
        $slots = $response->json('data');

        $this->assertNotEmpty($slots);
        $this->assertArrayHasKey('professional_id', $slots[0]);
        $this->assertContains($slots[0]['professional_id'], [$this->vetOne->id, $this->vetTwo->id]);
    }

    /**
     * Regressão (Fase 4): `AvailabilityAggregationService` delega a `AvailabilityService`
     * por profissional — esta prova que a correção do bug de `->first()` (uma única janela
     * por dia) também se propaga para o modo agregado, não só para a agenda individual.
     */
    public function test_aggregated_availability_returns_slots_from_both_windows_of_the_same_day(): void
    {
        $tomorrow = now()->addDay();

        Availability::create([
            'professional_id' => $this->vetOne->id,
            'organization_id' => $this->organization->id,
            'day_of_week' => $tomorrow->dayOfWeek,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'organization_id' => $this->organization->id,
            'date' => $tomorrow->toDateString(),
        ]));

        $response->assertOk();
        $startTimes = collect($response->json('data'))
            ->where('professional_id', $this->vetOne->id)
            ->map(fn (array $slot): string => substr($slot['start_time'], 11, 5));

        $this->assertTrue($startTimes->contains('08:00'), 'Janela da manhã (setUp) sumiu no modo agregado.');
        $this->assertTrue($startTimes->contains('14:00'), 'Segunda janela do dia sumiu no modo agregado.');
    }

    public function test_aggregated_availability_distributes_load_between_professionals(): void
    {
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();
        $slotTime = $tomorrow->copy()->setTime(8, 0);

        \App\Models\Appointment::create([
            'professional_id' => $this->vetOne->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'service_id' => $this->service->id,
            'appointment_date' => $slotTime,
            'duration' => 30,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'organization_id' => $this->organization->id,
            'date' => $tomorrow->toDateString(),
        ]));

        $slots = collect($response->json('data'));
        $firstSlot = $slots->firstWhere('start_time', $slotTime->copy()->toISOString());

        $this->assertSame($this->vetTwo->id, $firstSlot['professional_id']);
    }

    public function test_missing_both_professional_and_organization_is_rejected(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/public/booking/availability?date='.now()->addDay()->toDateString());

        $response->assertStatus(422);
    }

    public function test_available_days_endpoint_lists_days_with_a_free_slot(): void
    {
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();

        $response = $this->getJson('/api/public/booking/availability-days?'.http_build_query([
            'organization_id' => $this->organization->id,
            'month' => $tomorrow->format('Y-m'),
        ]));

        $response->assertOk();
        $this->assertContains($tomorrow->toDateString(), $response->json('data'));
    }

    public function test_aggregated_booking_resolves_and_persists_a_concrete_professional(): void
    {
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();
        $appointmentDate = $tomorrow->copy()->setTime(8, 0)->toDateTimeString();

        $response = $this->postJson('/api/public/booking', [
            'organization_id' => $this->organization->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $appointmentDate,
        ]);

        $response->assertCreated();
        $professionalId = $response->json('data.professional_id');

        $this->assertContains($professionalId, [$this->vetOne->id, $this->vetTwo->id]);
        $this->assertDatabaseHas('appointments', [
            'organization_id' => $this->organization->id,
            'professional_id' => $professionalId,
            'service_id' => $this->service->id,
        ]);
    }

    public function test_booking_rejects_a_professional_who_does_not_belong_to_the_organization(): void
    {
        Sanctum::actingAs($this->tutor);

        $outsider = User::factory()->professional()->create();
        $tomorrow = now()->addDay();

        $response = $this->postJson('/api/public/booking', [
            'organization_id' => $this->organization->id,
            'professional_id' => $outsider->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $tomorrow->copy()->setTime(8, 0)->toDateTimeString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('appointments', ['professional_id' => $outsider->id]);
    }

    public function test_booking_rejects_a_professional_who_does_not_offer_the_service(): void
    {
        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();

        $response = $this->postJson('/api/public/booking', [
            'organization_id' => $this->organization->id,
            'professional_id' => $this->vetTwo->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $tomorrow->copy()->setTime(8, 0)->toDateTimeString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('appointments', ['professional_id' => $this->vetTwo->id]);
    }

    /**
     * Regressão (Fase 6): `OrganizationServiceCatalog`/`OrganizationTeamService` agrupam
     * "quem oferece este serviço" por NOME normalizado — dois vets da mesma organização
     * com "Consulta Geral" (nomes iguais, ids diferentes) aparecem como o mesmo item do
     * catálogo. A validação do agendamento precisa concordar com isso: escolher o
     * profissional B com o `service_id` da linha do profissional A (mesmo nome, mesma
     * organização) tem que ser aceito, não 422.
     */
    public function test_booking_accepts_a_same_named_service_from_a_teammate_in_the_same_organization(): void
    {
        $vetTwoService = Service::create([
            'professional_id' => $this->vetTwo->id,
            'organization_id' => $this->organization->id,
            'name' => $this->service->name,
            'category' => 'consultation',
            'duration' => 30,
            'price' => 180.00,
            'active' => true,
        ]);

        Sanctum::actingAs($this->tutor);

        $tomorrow = now()->addDay();

        $response = $this->postJson('/api/public/booking', [
            'organization_id' => $this->organization->id,
            'professional_id' => $this->vetTwo->id,
            'service_id' => $this->service->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => $tomorrow->copy()->setTime(8, 0)->toDateTimeString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('appointments', [
            'professional_id' => $this->vetTwo->id,
            'service_id' => $this->service->id,
        ]);

        // Sanity: as duas linhas de serviço continuam distintas — o catálogo agrupou por
        // nome na LEITURA, não fundiu as linhas de verdade.
        $this->assertNotEquals($this->service->id, $vetTwoService->id);
    }
}
