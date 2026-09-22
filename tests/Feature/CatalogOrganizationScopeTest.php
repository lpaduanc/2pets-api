<?php

namespace Tests\Feature;

use App\Models\Coat;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Item 23 do backlog gap-simplesvet — cadastros configuráveis por dono, mesmo padrão de
 * `product_groups`/`brands`/`stock_exit_reasons` (`CommercialScopeResolver`). Sem catálogo
 * global: cada organização/profissional só vê o próprio cadastro.
 */
class CatalogOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function ownerFor(Organization $organization): User
    {
        $owner = User::factory()->tutor()->create();
        $owner->assignRole(Role::findOrCreate('clinic_owner', 'web'));
        OrganizationMember::factory()->owner()->for($organization)->for($owner, 'user')->create();

        return $owner;
    }

    public function test_organization_only_sees_its_own_catalog_item(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $ownerA = $this->ownerFor($organizationA);
        $this->ownerFor($organizationB);

        Coat::create(['organization_id' => $organizationA->id, 'name' => 'Pelo curto']);
        Coat::create(['organization_id' => $organizationB->id, 'name' => 'Pelo longo']);

        $response = $this->actingAs($ownerA)->getJson('/api/catalogs/coats');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Pelo curto', $names);
        $this->assertNotContains('Pelo longo', $names);
    }

    public function test_user_without_catalog_manage_cannot_create_item(): void
    {
        $organization = Organization::factory()->create();
        $vet = User::factory()->tutor()->create();
        $vet->assignRole(Role::findOrCreate('clinic_vet', 'web'));
        OrganizationMember::factory()->for($organization)->for($vet, 'user')->create();

        $this->actingAs($vet)
            ->postJson('/api/catalogs/coats', ['name' => 'Pelo encaracolado'])
            ->assertForbidden();
    }

    public function test_owner_with_catalog_manage_can_create_item(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->ownerFor($organization);

        $response = $this->actingAs($owner)->postJson('/api/catalogs/coats', ['name' => 'Pelo encaracolado']);

        $response->assertCreated();
        $this->assertDatabaseHas('coats', [
            'organization_id' => $organization->id,
            'name' => 'Pelo encaracolado',
        ]);
    }

    public function test_duplicate_name_for_the_same_owner_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->ownerFor($organization);
        Coat::create(['organization_id' => $organization->id, 'name' => 'Pelo curto']);

        $response = $this->actingAs($owner)->postJson('/api/catalogs/coats', ['name' => 'Pelo curto']);

        $response->assertStatus(422);
    }

    public function test_unknown_catalog_type_returns_404(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->ownerFor($organization);

        $this->actingAs($owner)->getJson('/api/catalogs/does-not-exist')->assertNotFound();
    }
}
