<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 7 do fluxo de agendamento: "uma clínica com um cardiologista na equipe aparece
 * buscando por cardiologia?" — antes desta fase, não. `professionals.team_specialties`
 * (espelhado por `App\Services\Organization\TeamSpecialtyAggregator`, mantido por
 * observer) resolve isso sem mudar a FORMA da subquery de especialidade que já existe
 * (`ProfessionalAttributeFilter`) — ver o relato da tarefa para o `EXPLAIN ANALYZE` que
 * descartou a alternativa de `EXISTS` correlacionado.
 */
class TeamSpecialtySearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $professionalOverrides
     */
    private function createProfessional(string $name, array $professionalOverrides = []): User
    {
        $user = User::factory()->professional()->create([
            'name' => $name,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);

        Professional::factory()->create([
            'user_id' => $user->id,
            'business_name' => null,
            'description' => null,
            'specialties' => [],
            'species_served' => null,
            'professional_type' => 'vet',
            ...$professionalOverrides,
        ]);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function searchIdsFor(string $specialty): array
    {
        $response = $this->getJson('/api/public/search?'.http_build_query(['specialty' => [$specialty]]));
        $response->assertOk();

        return array_map(static fn (array $item): int => $item['id'], $response->json('data'));
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function createClinicWithOwner(): array
    {
        $owner = $this->createProfessional('Clínica Central', ['professional_type' => 'clinic']);
        $organization = Organization::factory()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        return [$owner, $organization];
    }

    private function addTeamMember(Organization $organization, User $member, string $role = OrganizationMember::ROLE_VETERINARIAN, bool $active = true): void
    {
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    // ---------------------------------------------------------------
    // Não regredir: busca por especialidade PRÓPRIA continua idêntica
    // ---------------------------------------------------------------

    public function test_search_by_a_professionals_own_specialty_is_unaffected(): void
    {
        $cardiologist = $this->createProfessional('Dr. Solo Cardiologista', ['specialties' => ['cardiologia']]);
        $dermatologist = $this->createProfessional('Dra. Solo Dermatologista', ['specialties' => ['dermatologia']]);

        $matched = $this->searchIdsFor('cardiologia');

        $this->assertContains($cardiologist->id, $matched);
        $this->assertNotContains($dermatologist->id, $matched);
    }

    // ---------------------------------------------------------------
    // Caso novo: clínica aparece pela especialidade da equipe
    // ---------------------------------------------------------------

    /**
     * Critério de aceite explícito: um cenário SEM nenhuma organização envolvida (o que a
     * busca já cobria antes desta fase) devolve o MESMO conjunto de ids, na MESMA ordem,
     * em duas chamadas idênticas — a nova coluna (`team_specialties`, sempre `NULL` aqui)
     * não pode reordenar nem filtrar nada que já funcionava.
     */
    public function test_a_pre_existing_search_scenario_returns_the_exact_same_ids_in_the_exact_same_order(): void
    {
        // Sem geolocalização, o sort padrão é `orderBy('users.name')` — nomes escolhidos
        // de propósito em ordem alfabética para a asserção de ORDEM ser sobre o
        // comportamento real da busca, não coincidência.
        $first = $this->createProfessional('Ana Cardiologista', ['specialties' => ['cardiologia']]);
        $second = $this->createProfessional('Bruno Cardiologista', ['specialties' => ['cardiologia']]);
        $third = $this->createProfessional('Carla Cardiologista', ['specialties' => ['cardiologia']]);
        $unrelated = $this->createProfessional('Daniel Dermatologista', ['specialties' => ['dermatologia']]);

        $before = $this->searchIdsFor('cardiologia');
        $after = $this->searchIdsFor('cardiologia');

        $this->assertSame($before, $after, 'A mesma busca, chamada duas vezes, devolveu ordens diferentes.');
        $this->assertSame([$first->id, $second->id, $third->id], $before);
        $this->assertNotContains($unrelated->id, $before);
    }

    public function test_clinic_appears_in_search_for_a_specialty_only_a_team_member_has(): void
    {
        [$owner, $organization] = $this->createClinicWithOwner();

        $generalist = $this->createProfessional('Dr. Generalista', ['specialties' => ['clinica_geral']]);
        $cardiologist = $this->createProfessional('Dra. Cardiologista da Equipe', ['specialties' => ['cardiologia']]);
        $surgeon = $this->createProfessional('Dr. Cirurgião da Equipe', ['specialties' => ['cirurgia']]);

        $this->addTeamMember($organization, $generalist);
        $this->addTeamMember($organization, $cardiologist);
        $this->addTeamMember($organization, $surgeon);

        $matched = $this->searchIdsFor('cardiologia');

        $this->assertContains($owner->id, $matched, 'A clínica não apareceu buscando a especialidade de um membro da equipe.');
        $this->assertContains($cardiologist->id, $matched, 'A cardiologista continua aparecendo pela própria especialidade (ambos podem aparecer).');
    }

    public function test_clinic_does_not_appear_for_a_specialty_nobody_on_the_team_has(): void
    {
        [$owner, $organization] = $this->createClinicWithOwner();

        $generalist = $this->createProfessional('Dr. Generalista 2', ['specialties' => ['clinica_geral']]);
        $this->addTeamMember($organization, $generalist);

        $matched = $this->searchIdsFor('dermatologia');

        $this->assertNotContains($owner->id, $matched);
    }

    // ---------------------------------------------------------------
    // Semântica: só membro ATIVO e em papel BOOKÁVEL conta
    // ---------------------------------------------------------------

    public function test_inactive_member_specialty_does_not_make_the_clinic_appear(): void
    {
        [$owner, $organization] = $this->createClinicWithOwner();

        $exCardiologist = $this->createProfessional('Ex-cardiologista', ['specialties' => ['cardiologia']]);
        $this->addTeamMember($organization, $exCardiologist, OrganizationMember::ROLE_VETERINARIAN, active: false);

        $matched = $this->searchIdsFor('cardiologia');

        $this->assertNotContains($owner->id, $matched);
    }

    public function test_receptionist_specialty_does_not_make_the_clinic_appear(): void
    {
        [$owner, $organization] = $this->createClinicWithOwner();

        $receptionistWithSpecialtyField = $this->createProfessional('Recepcionista', ['specialties' => ['cardiologia']]);
        $this->addTeamMember($organization, $receptionistWithSpecialtyField, OrganizationMember::ROLE_RECEPTIONIST);

        $matched = $this->searchIdsFor('cardiologia');

        $this->assertNotContains($owner->id, $matched);
    }

    // ---------------------------------------------------------------
    // Consistência: o agregado acompanha mudanças na equipe
    // ---------------------------------------------------------------

    public function test_removing_the_only_carrier_of_a_specialty_removes_the_clinic_from_that_search(): void
    {
        [$owner, $organization] = $this->createClinicWithOwner();

        $cardiologist = $this->createProfessional('Cardiologista que vai sair', ['specialties' => ['cardiologia']]);
        $membership = OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $cardiologist->id,
        ]);

        $this->assertContains($owner->id, $this->searchIdsFor('cardiologia'));

        $membership->delete();

        $this->assertNotContains($owner->id, $this->searchIdsFor('cardiologia'));
    }

    public function test_a_member_changing_specialty_updates_the_aggregate(): void
    {
        [$owner, $organization] = $this->createClinicWithOwner();

        $member = $this->createProfessional('Vet que muda de especialidade', ['specialties' => ['clinica_geral']]);
        $this->addTeamMember($organization, $member);

        $this->assertNotContains($owner->id, $this->searchIdsFor('cardiologia'));

        $member->professional->update(['specialties' => ['cardiologia']]);

        $this->assertContains($owner->id, $this->searchIdsFor('cardiologia'));
    }

    public function test_deactivating_a_member_removes_the_clinic_from_that_search(): void
    {
        [$owner, $organization] = $this->createClinicWithOwner();

        $cardiologist = $this->createProfessional('Cardiologista que será desativado', ['specialties' => ['cardiologia']]);
        $membership = OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $cardiologist->id,
        ]);

        $this->assertContains($owner->id, $this->searchIdsFor('cardiologia'));

        $membership->update(['is_active' => false]);

        $this->assertNotContains($owner->id, $this->searchIdsFor('cardiologia'));
    }
}
