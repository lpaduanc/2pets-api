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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET professional/agenda/day` — item 21 do backlog gap-simplesvet
 * (docs/gap-simplesvet/specs/21-agenda-escala-bloqueios-recursos-spec.md §Endpoints
 * sugeridos). Grade recurso × hora, sem N+1.
 */
class DayAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_sees_the_whole_team_grouped_by_professional(): void
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
        $this->createAppointment($vet, $organization, $owner, $pet);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/professional/agenda/day?date='.now()->toDateString());

        $response->assertOk();
        $resources = collect($response->json('resources'));
        $this->assertCount(2, $resources);
        $vetResource = $resources->firstWhere('id', $vet->id);
        $this->assertCount(1, $vetResource['appointments']);
        $ownerResource = $resources->firstWhere('id', $owner->id);
        $this->assertCount(0, $ownerResource['appointments']);
    }

    public function test_freelancer_without_organization_sees_only_their_own_resource(): void
    {
        $freelancer = User::factory()->professional()->create();
        Sanctum::actingAs($freelancer);

        $response = $this->getJson('/api/professional/agenda/day?date='.now()->toDateString());

        $response->assertOk();
        $resources = $response->json('resources');
        $this->assertCount(1, $resources);
        $this->assertSame($freelancer->id, $resources[0]['id']);
    }

    public function test_area_filter_excludes_a_member_scoped_to_a_different_area(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();
        $groomer = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($organization, 'organization')->create(['user_id' => $owner->id]);
        $groomerMember = OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $groomer->id,
            'role' => OrganizationRole::GROOMER->value,
        ]);
        $groomingArea = \App\Models\ServiceArea::create(['organization_id' => $organization->id, 'name' => 'Banho e Tosa']);
        $surgeryArea = \App\Models\ServiceArea::create(['organization_id' => $organization->id, 'name' => 'Cirurgia']);
        $groomerMember->serviceAreas()->attach($groomingArea->id);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/professional/agenda/day?date='.now()->toDateString()."&area_id={$surgeryArea->id}");

        $response->assertOk();
        $ids = collect($response->json('resources'))->pluck('id');
        $this->assertFalse($ids->contains($groomer->id));
    }

    public function test_endpoint_runs_a_fixed_number_of_queries_regardless_of_team_and_appointment_size(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($organization, 'organization')->create(['user_id' => $owner->id]);

        for ($i = 0; $i < 8; $i++) {
            $vet = User::factory()->professional()->create();
            OrganizationMember::factory()->for($organization, 'organization')->create([
                'user_id' => $vet->id,
                'role' => OrganizationRole::VETERINARIAN->value,
            ]);
            $pet = Pet::factory()->create(['user_id' => $owner->id]);

            for ($j = 0; $j < 7; $j++) {
                $this->createAppointment($vet, $organization, $owner, $pet);
            }
        }

        Sanctum::actingAs($owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/professional/agenda/day?date='.now()->toDateString())->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Query por profissional (N+1) daria, no mínimo, mais 8 consultas — o teto aqui só
        // sobra espaço para as queries fixas do endpoint (membros, agendamentos + eager
        // loads, autenticação Sanctum), nunca uma por recurso.
        $this->assertLessThanOrEqual(
            12,
            $queryCount,
            "Endpoint executou {$queryCount} queries para 8 profissionais/56 agendamentos — indício de N+1."
        );
    }

    private function createAppointment(User $professional, Organization $organization, User $client, Pet $pet): Appointment
    {
        return Appointment::create([
            'professional_id' => $professional->id,
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'confirmed',
        ]);
    }
}
