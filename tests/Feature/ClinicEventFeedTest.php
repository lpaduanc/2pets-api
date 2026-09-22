<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Models\Vaccination;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET reports/clinic-events` — contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`.
 * Escopo por EQUIPE (`CommercialScopeResolver::teamUserIds()`), nunca por `organization_id`
 * cru — mesma regra que já corrigiu o bug real de `HospitalizationController`. Teste escrito
 * conforme a regra do projeto: NÃO executado via `artisan test`.
 */
class ClinicEventFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_merges_appointment_and_vaccination_sorted_by_date(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $client->id]);
        ProfessionalClient::create(['professional_id' => $vet->id, 'client_id' => $client->id]);

        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->subDays(2)->toDateString(),
            'appointment_time' => '10:00:00',
            'status' => 'completed',
        ]);

        Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => $vet->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->subDay(),
        ]);

        Sanctum::actingAs($vet);

        $response = $this->getJson('/api/professional/reports/clinic-events?'.http_build_query([
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk();

        $types = collect($response->json('data.items'))->pluck('type')->all();
        $this->assertSame(['vaccination', 'appointment'], $types);
    }

    public function test_event_type_filter_returns_only_requested_type(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $client->id]);
        ProfessionalClient::create(['professional_id' => $vet->id, 'client_id' => $client->id]);

        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '10:00:00',
            'status' => 'completed',
        ]);

        Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => $vet->id,
            'vaccine_name' => 'V10',
            'application_date' => now(),
        ]);

        Sanctum::actingAs($vet);

        $response = $this->getJson('/api/professional/reports/clinic-events?'.http_build_query([
            'event_type' => ['vaccination'],
        ]))->assertOk();

        $types = collect($response->json('data.items'))->pluck('type')->unique()->all();
        $this->assertSame(['vaccination'], $types);
    }

    /**
     * Vet de uma clínica não vê evento de pet de cliente que só tem vínculo com outra
     * clínica — critério de aceite explícito da spec.
     */
    public function test_vet_does_not_see_events_of_a_pet_from_another_clinic(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $vetA = User::factory()->professional()->create();
        $vetB = User::factory()->professional()->create();

        $clientB = User::factory()->tutor()->create();
        $petB = Pet::factory()->create(['user_id' => $clientB->id]);
        ProfessionalClient::create(['professional_id' => $vetB->id, 'client_id' => $clientB->id]);

        Vaccination::create([
            'pet_id' => $petB->id,
            'professional_id' => $vetB->id,
            'vaccine_name' => 'V10',
            'application_date' => now(),
        ]);

        Sanctum::actingAs($vetA);

        $response = $this->getJson('/api/professional/reports/clinic-events')->assertOk();

        $this->assertSame(0, $response->json('data.total'));
    }
}
