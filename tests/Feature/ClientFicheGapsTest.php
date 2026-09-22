<?php

namespace Tests\Feature;

use App\Enums\PetVetAccessOrigin;
use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\ProfessionalClient;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lacunas de backend relatadas pelo frontend do 18/19: `PetVetAccessResource.origin`,
 * `GET clients/{id}/relationship-profile` e `GET clients/{id}/tags`. Teste escrito conforme a
 * regra do projeto: NÃO executado via `artisan test`.
 */
class ClientFicheGapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pet_vet_access_resource_exposes_origin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $access = PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $vet->id,
            'origin' => PetVetAccessOrigin::NEW_PATIENT_PENDING_REQUEST,
            'requested_access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_PENDING,
            'requested_at' => now(),
            'is_active' => false,
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/me/pending-links')->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $access->id);
        $this->assertSame('new_patient_pending_request', $item['origin']);
    }

    public function test_relationship_profile_endpoint_returns_lifecycle_abc_origin_and_tags(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        ProfessionalClient::create(['professional_id' => $professional->id, 'client_id' => $client->id]);

        $tag = Tag::create(['professional_id' => $professional->id, 'name' => 'VIP']);
        $client->tags()->attach($tag->id);

        Sanctum::actingAs($professional);

        $response = $this->getJson("/api/professional/clients/{$client->id}/relationship-profile");

        $response->assertOk();
        $response->assertJsonPath('data.client.id', $client->id);
        $response->assertJsonPath('data.lifecycle_stage', 'no_purchase_yet');
        $tagNames = collect($response->json('data.tags'))->pluck('name');
        $this->assertContains('VIP', $tagNames);
    }

    public function test_client_tags_index_lists_attached_tags(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        ProfessionalClient::create(['professional_id' => $professional->id, 'client_id' => $client->id]);

        $tag = Tag::create(['professional_id' => $professional->id, 'name' => 'Inadimplente']);
        $client->tags()->attach($tag->id);

        Sanctum::actingAs($professional);

        $response = $this->getJson("/api/professional/clients/{$client->id}/tags")->assertOk();

        $this->assertSame('Inadimplente', $response->json('data.0.name'));
    }
}
