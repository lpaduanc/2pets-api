<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/user` precisa expor os vínculos ATIVOS de organização do usuário logado — é como o
 * frontend descobre o `organization_id` pra chamar `/api/organizations/{organization}/members`,
 * sem o fallback de adivinhação que existia antes desta chave existir.
 */
class UserOrganizationsExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_tutor_gets_an_empty_organizations_array(): void
    {
        $tutor = User::factory()->tutor()->create();

        $response = $this->actingAs($tutor, 'sanctum')->getJson('/api/user');

        $response->assertOk()->assertJsonPath('data.organizations', []);
    }

    public function test_returns_every_active_organization_the_user_belongs_to(): void
    {
        $person = User::factory()->tutor()->create();
        $clinic = Organization::factory()->create(['business_name' => 'Clínica X']);
        $petshop = Organization::factory()->petshop()->create(['business_name' => 'Petshop Y']);
        OrganizationMember::factory()->owner()->for($clinic, 'organization')->create(['user_id' => $person->id]);
        OrganizationMember::factory()->for($petshop, 'organization')->create([
            'user_id' => $person->id,
            'role' => OrganizationRole::GROOMER->value,
        ]);

        $response = $this->actingAs($person, 'sanctum')->getJson('/api/user');

        $response->assertOk()
            ->assertJsonCount(2, 'data.organizations')
            ->assertJsonFragment(['id' => $clinic->id, 'name' => 'Clínica X', 'role' => 'owner', 'role_label' => 'Proprietário'])
            ->assertJsonFragment(['id' => $petshop->id, 'name' => 'Petshop Y', 'role' => 'groomer', 'role_label' => 'Banho e Tosa']);
    }

    public function test_inactive_membership_is_not_exposed(): void
    {
        $person = User::factory()->tutor()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->for($organization, 'organization')->create([
            'user_id' => $person->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($person, 'sanctum')->getJson('/api/user');

        $response->assertOk()->assertJsonPath('data.organizations', []);
    }

    public function test_organizations_key_does_not_grow_query_count_per_membership(): void
    {
        $personWithOne = User::factory()->tutor()->create();
        OrganizationMember::factory()->owner()->create(['user_id' => $personWithOne->id]);

        $personWithThree = User::factory()->tutor()->create();
        OrganizationMember::factory()->owner()->count(3)->create(['user_id' => $personWithThree->id]);

        $queryCountFor = function (User $user): int {
            DB::enableQueryLog();
            $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $count;
        };

        $this->assertSame($queryCountFor($personWithOne), $queryCountFor($personWithThree));
    }
}
