<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\User;
use App\Services\Organization\OrganizationTeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 21 do backlog gap-simplesvet, regra 2: `service_areas` restringe elegibilidade, não é
 * obrigatório. Profissional sem nenhuma área associada continua atendendo qualquer serviço
 * (comportamento não regressivo). Cada profissional recebe o próprio `Service` (mesmo nome do
 * serviço testado) para isolar a restrição de ÁREA do filtro de nome já existente em
 * `OrganizationTeamService::filterByService()` — sem isso o teste provaria o filtro errado.
 */
class ServiceAreaRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithOwnService(Organization $organization, string $role, string $serviceName, ?int $serviceAreaId): array
    {
        $user = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $user->id]);
        $member = OrganizationMember::factory()->for($organization)->for($user, 'user')->create(['role' => $role]);

        $service = Service::create([
            'organization_id' => $organization->id,
            'professional_id' => $user->id,
            'service_area_id' => $serviceAreaId,
            'name' => $serviceName,
            'category' => \App\Enums\ServiceCategory::OTHER->value,
            'duration' => 30,
            'price' => 50,
            'active' => true,
        ]);

        return [$member, $service];
    }

    public function test_member_with_matching_area_is_bookable_for_the_service(): void
    {
        $organization = Organization::factory()->create();
        $groomingArea = ServiceArea::create(['organization_id' => $organization->id, 'name' => 'Banho e Tosa']);

        [$member, $service] = $this->memberWithOwnService($organization, OrganizationMember::ROLE_GROOMER, 'Banho', $groomingArea->id);
        $member->serviceAreas()->attach($groomingArea->id);

        $team = app(OrganizationTeamService::class)->bookableMembers($organization, $service->id);

        $this->assertTrue($team->contains('id', $member->user_id));
    }

    public function test_member_with_area_is_not_bookable_for_a_different_area_service(): void
    {
        $organization = Organization::factory()->create();
        $groomingArea = ServiceArea::create(['organization_id' => $organization->id, 'name' => 'Banho e Tosa']);
        $surgeryArea = ServiceArea::create(['organization_id' => $organization->id, 'name' => 'Cirurgia']);

        [$member, $service] = $this->memberWithOwnService($organization, OrganizationMember::ROLE_GROOMER, 'Cirurgia geral', $surgeryArea->id);
        $member->serviceAreas()->attach($groomingArea->id);

        $team = app(OrganizationTeamService::class)->bookableMembers($organization, $service->id);

        $this->assertFalse($team->contains('id', $member->user_id));
    }

    public function test_member_without_any_area_remains_bookable_for_any_service(): void
    {
        $organization = Organization::factory()->create();
        $surgeryArea = ServiceArea::create(['organization_id' => $organization->id, 'name' => 'Cirurgia']);

        [$member, $service] = $this->memberWithOwnService($organization, OrganizationMember::ROLE_VETERINARIAN, 'Cirurgia geral', $surgeryArea->id);
        // Nenhuma área associada ao member — sem restrição (comportamento não regressivo).

        $team = app(OrganizationTeamService::class)->bookableMembers($organization, $service->id);

        $this->assertTrue($team->contains('id', $member->user_id));
    }
}
