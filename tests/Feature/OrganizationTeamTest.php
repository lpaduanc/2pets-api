<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2 do fluxo de agendamento: "se for uma clínica, mostre os profissionais
 * disponíveis" — `GET /api/public/professionals/{id}/team`.
 */
class OrganizationTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_professional_has_no_team(): void
    {
        $vet = User::factory()->professional()->create();

        $response = $this->getJson("/api/public/professionals/{$vet->id}/team");

        $response->assertOk()
            ->assertJson(['has_team' => false, 'data' => []]);
    }

    public function test_clinic_owner_lists_active_team_members(): void
    {
        [$owner, $organization] = $this->createOrganizationWithOwner();

        $vetOne = User::factory()->professional()->create(['name' => 'Dra. Ana']);
        $vetTwo = User::factory()->professional()->create(['name' => 'Dr. Bruno']);
        $inactiveVet = User::factory()->professional()->create(['name' => 'Dr. Carlos (inativo)']);

        OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $vetOne->id]);
        OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $vetTwo->id]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $inactiveVet->id,
            'is_active' => false,
        ]);

        $response = $this->getJson("/api/public/professionals/{$owner->id}/team");

        $response->assertOk()->assertJson(['has_team' => true, 'organization_id' => $organization->id]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($vetOne->id));
        $this->assertTrue($ids->contains($vetTwo->id));
        $this->assertFalse($ids->contains($inactiveVet->id));
    }

    public function test_receptionist_role_never_appears_as_bookable(): void
    {
        [$owner, $organization] = $this->createOrganizationWithOwner();

        $receptionist = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $receptionist->id,
            'role' => OrganizationMember::ROLE_RECEPTIONIST,
        ]);

        $response = $this->getJson("/api/public/professionals/{$owner->id}/team");

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($receptionist->id));
    }

    public function test_filtering_by_service_id_returns_only_professionals_who_offer_it(): void
    {
        [$owner, $organization] = $this->createOrganizationWithOwner();

        $cardiologist = User::factory()->professional()->create();
        $generalist = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $cardiologist->id]);
        Professional::factory()->create(['user_id' => $generalist->id]);

        OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $cardiologist->id]);
        OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $generalist->id]);

        $cardiologyService = Service::create([
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'name' => 'Consulta Cardiológica',
            'category' => 'consultation',
            'duration' => 40,
            'price' => 250.00,
            'active' => true,
        ]);

        Service::create([
            'professional_id' => $generalist->id,
            'organization_id' => $organization->id,
            'name' => 'Consulta Geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150.00,
            'active' => true,
        ]);

        $response = $this->getJson("/api/public/professionals/{$owner->id}/team?service_id={$cardiologyService->id}");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($cardiologist->id, $data[0]['id']);
        $this->assertEquals(250.0, $data[0]['price']);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function createOrganizationWithOwner(): array
    {
        $owner = User::factory()->professional()->create();
        $organization = Organization::factory()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        return [$owner, $organization];
    }
}
