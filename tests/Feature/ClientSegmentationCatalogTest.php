<?php

namespace Tests\Feature;

use App\Models\ClientOrigin;
use App\Models\ClientRelationshipProfile;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Escrito conforme a
 * regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class ClientSegmentationCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_origins_index_lists_global_catalog_for_freelance_professional(): void
    {
        $professional = User::factory()->professional()->create();
        Sanctum::actingAs($professional);

        $response = $this->getJson('/api/professional/client-origins');

        $response->assertOk();
        $this->assertContains('Busca no 2pets', $response->json('data.*.name'));
    }

    public function test_organization_owner_can_create_custom_client_origin(): void
    {
        [$owner] = $this->createOrganizationOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/professional/client-origins', ['name' => 'Feira de adoção']);

        $response->assertCreated()->assertJsonPath('data.name', 'Feira de adoção');
        $this->assertDatabaseHas('client_origins', ['name' => 'Feira de adoção']);
    }

    public function test_freelance_professional_cannot_create_client_origin_without_organization(): void
    {
        $professional = User::factory()->professional()->create();
        Sanctum::actingAs($professional);

        $this->postJson('/api/professional/client-origins', ['name' => 'Panfleto na feira'])
            ->assertStatus(422);
    }

    public function test_global_client_origin_cannot_be_deleted_by_organization_owner(): void
    {
        [$owner] = $this->createOrganizationOwner();
        Sanctum::actingAs($owner);

        $global = ClientOrigin::where('name', 'Busca no 2pets')->firstOrFail();

        $this->deleteJson("/api/professional/client-origins/{$global->id}")->assertStatus(403);
    }

    public function test_tags_can_be_created_and_attached_to_a_client(): void
    {
        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        \App\Models\ProfessionalClient::create([
            'professional_id' => $professional->id,
            'client_id' => $client->id,
        ]);

        Sanctum::actingAs($professional);

        $this->postJson("/api/professional/clients/{$client->id}/tags", ['name' => 'VIP'])
            ->assertCreated();

        $client->refresh();
        $this->assertTrue($client->tags()->where('name', 'VIP')->exists());
    }

    public function test_client_segment_can_be_saved_and_listed(): void
    {
        $professional = User::factory()->professional()->create();
        Sanctum::actingAs($professional);

        $definition = ['lifecycle_stage' => ['quiet_3_6m'], 'species' => 'dog'];

        $store = $this->postJson('/api/professional/client-segments', [
            'name' => 'Cães quietos',
            'definition' => $definition,
        ]);

        $store->assertCreated()->assertJsonPath('data.name', 'Cães quietos');

        $this->getJson('/api/professional/client-segments')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cães quietos');
    }

    public function test_clients_search_returns_only_this_professional_clients(): void
    {
        $professional = User::factory()->professional()->create();
        $otherProfessional = User::factory()->professional()->create();

        $client = User::factory()->tutor()->create();
        $otherClient = User::factory()->tutor()->create();

        ClientRelationshipProfile::create([
            'organization_id' => null,
            'professional_id' => $professional->id,
            'client_id' => $client->id,
            'lifecycle_stage' => 'returned_recently',
            'recalculated_at' => now(),
        ]);
        ClientRelationshipProfile::create([
            'organization_id' => null,
            'professional_id' => $otherProfessional->id,
            'client_id' => $otherClient->id,
            'lifecycle_stage' => 'returned_recently',
            'recalculated_at' => now(),
        ]);

        Sanctum::actingAs($professional);

        $response = $this->postJson('/api/professional/clients/search', ['segment' => []]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('client.id');
        $this->assertTrue($ids->contains($client->id));
        $this->assertFalse($ids->contains($otherClient->id));
    }

    /** @return array{0: User, 1: Organization} */
    private function createOrganizationOwner(): array
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
