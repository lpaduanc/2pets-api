<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG real (docs/vinculo-estoque-aplicacao-clinica.md item 5, achado em E2E manual 2026-09-13):
 * `InventoryController` filtrava `index`/`show`/`update`/`destroy` só por `professional_id`
 * cru e nunca lia `organization_id`, mesmo a coluna já existindo — dois veterinários da mesma
 * clínica não enxergavam o mesmo estoque.
 */
class InventoryOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_members_of_the_same_organization_see_the_same_inventory(): void
    {
        $organization = Organization::factory()->create();
        $ownerVet = User::factory()->professional()->create();
        $employeeVet = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $ownerVet->id,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $employeeVet->id,
        ]);

        Sanctum::actingAs($ownerVet);
        $this->postJson('/api/professional/inventory', [
            'item_name' => 'Vacina V10',
            'category' => 'vaccine',
            'quantity' => 10,
        ])->assertCreated();

        Sanctum::actingAs($employeeVet);
        $response = $this->getJson('/api/professional/inventory')->assertOk();

        $names = collect($response->json('data'))->pluck('item_name');
        $this->assertTrue($names->contains('Vacina V10'), 'employee must see the item the owner registered');
    }

    public function test_a_freelance_vet_without_organization_only_sees_their_own_items(): void
    {
        $freelancerA = User::factory()->professional()->create();
        $freelancerB = User::factory()->professional()->create();

        Inventory::create([
            'professional_id' => $freelancerA->id,
            'item_name' => 'Item do A',
            'category' => 'supply',
            'quantity' => 5,
        ]);
        Inventory::create([
            'professional_id' => $freelancerB->id,
            'item_name' => 'Item do B',
            'category' => 'supply',
            'quantity' => 5,
        ]);

        Sanctum::actingAs($freelancerA);
        $response = $this->getJson('/api/professional/inventory')->assertOk();

        $names = collect($response->json('data'))->pluck('item_name');
        $this->assertTrue($names->contains('Item do A'));
        $this->assertFalse($names->contains('Item do B'), 'freelancer must stay isolated from another freelancer');
    }

    public function test_a_member_of_another_organization_cannot_see_or_update_the_item(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $vetA = User::factory()->professional()->create();
        $vetB = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->create(['organization_id' => $orgA->id, 'user_id' => $vetA->id]);
        OrganizationMember::factory()->owner()->create(['organization_id' => $orgB->id, 'user_id' => $vetB->id]);

        Sanctum::actingAs($vetA);
        $itemId = $this->postJson('/api/professional/inventory', [
            'item_name' => 'Vacina da clínica A',
            'category' => 'vaccine',
            'quantity' => 10,
        ])->assertCreated()->json('item.id');

        Sanctum::actingAs($vetB);
        $this->getJson("/api/professional/inventory/{$itemId}")->assertNotFound();
        $this->putJson("/api/professional/inventory/{$itemId}", ['quantity' => 999])->assertNotFound();
    }

    public function test_creating_an_item_as_an_organization_member_stores_the_organization_id(): void
    {
        $organization = Organization::factory()->create();
        $vet = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $vet->id,
        ]);

        Sanctum::actingAs($vet);
        $this->postJson('/api/professional/inventory', [
            'item_name' => 'Ração terapêutica',
            'category' => 'supply',
            'quantity' => 3,
        ])->assertCreated();

        $this->assertDatabaseHas('inventories', [
            'item_name' => 'Ração terapêutica',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_creating_an_item_records_the_initial_stock_movement(): void
    {
        $vet = User::factory()->professional()->create();

        Sanctum::actingAs($vet);
        $itemId = $this->postJson('/api/professional/inventory', [
            'item_name' => 'Seringas',
            'category' => 'supply',
            'quantity' => 20,
        ])->assertCreated()->json('item.id');

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $itemId,
            'quantity_delta' => 20,
            'type' => 'purchase_in',
        ]);
    }

    public function test_manual_quantity_adjustment_creates_a_ledger_movement(): void
    {
        $vet = User::factory()->professional()->create();
        $item = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Seringas',
            'category' => 'supply',
            'quantity' => 10,
        ]);

        Sanctum::actingAs($vet);
        $this->putJson("/api/professional/inventory/{$item->id}", ['quantity' => 7])->assertOk();

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $item->id,
            'quantity_delta' => -3,
            'type' => 'adjustment_decrease',
        ]);
    }

    /**
     * REGRESSÃO (relatada pelo coordenador em dev, 2026-09-15): a migration
     * `2026_09_14_100100_backfill_organization_id_on_commercial_tables` só rodou UMA VEZ.
     * Qualquer item de estoque criado DEPOIS dela e ANTES do escopo por organização entrar em
     * vigor nasceu órfão — `organization_id` NULL com `professional_id` de um dono que JÁ tinha
     * organização ativa. Sem rede de segurança, esse item some da listagem do próprio dono: o
     * ramo de organização não pega (`organization_id` é null) e o ramo de freelancer nunca é
     * alcançado (o dono TEM organização). Reproduz o caso real medido em dev (6 de 12 itens).
     */
    public function test_an_organization_owners_legacy_item_without_organization_id_still_appears(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        $legacyItem = Inventory::create([
            'professional_id' => $owner->id,
            'organization_id' => null,
            'item_name' => 'Vacina V10 (item legado)',
            'category' => 'vaccine',
            'quantity' => 5,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/professional/inventory/{$legacyItem->id}")->assertOk();

        $names = collect($this->getJson('/api/professional/inventory')->json('data'))->pluck('item_name');
        $this->assertTrue($names->contains('Vacina V10 (item legado)'));
    }

    /**
     * `CommercialOrganizationBackfillService` (reexecutado em
     * `2026_09_15_120000_rerun_commercial_organization_backfill.php`) preenche `organization_id`
     * de forma definitiva para o dono de exatamente UMA organização — a rede de segurança do
     * teste acima cobre o meio-tempo, o backfill fecha o dado de vez.
     */
    public function test_rerunning_the_backfill_service_fills_the_legacy_items_organization_id(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        $legacyItem = Inventory::create([
            'professional_id' => $owner->id,
            'organization_id' => null,
            'item_name' => 'Vacina Antirrábica (item legado)',
            'category' => 'vaccine',
            'quantity' => 3,
        ]);

        app(\App\Services\Organization\CommercialOrganizationBackfillService::class)->run();

        $this->assertSame($organization->id, $legacyItem->fresh()->organization_id);
    }

    /**
     * Dono de MAIS de uma organização é o único caso que nenhum backfill automático resolve —
     * `CommercialOrganizationBackfillService` deixa `organization_id` NULL de propósito por
     * ambiguidade (não há como inferir a qual das organizações a linha pertence). Sem a rede de
     * segurança em `InventoryScopeResolver::scopeQuery()`, esse item ficaria invisível PARA
     * SEMPRE, não só até o próximo backfill.
     */
    public function test_an_ambiguous_multi_organization_owners_legacy_item_still_appears(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $owner = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->create(['organization_id' => $orgA->id, 'user_id' => $owner->id]);
        OrganizationMember::factory()->owner()->create(['organization_id' => $orgB->id, 'user_id' => $owner->id]);

        $legacyItem = Inventory::create([
            'professional_id' => $owner->id,
            'organization_id' => null,
            'item_name' => 'Item de dono ambíguo',
            'category' => 'supply',
            'quantity' => 1,
        ]);

        app(\App\Services\Organization\CommercialOrganizationBackfillService::class)->run();
        $this->assertNull($legacyItem->fresh()->organization_id, 'backfill deliberately skips ambiguous owners');

        Sanctum::actingAs($owner);
        $names = collect($this->getJson('/api/professional/inventory')->json('data'))->pluck('item_name');
        $this->assertTrue($names->contains('Item de dono ambíguo'), 'safety net must cover what backfill cannot');
    }

    public function test_deleting_an_item_soft_deletes_it(): void
    {
        $vet = User::factory()->professional()->create();
        $item = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Coleira',
            'category' => 'supply',
            'quantity' => 1,
        ]);

        Sanctum::actingAs($vet);
        $this->deleteJson("/api/professional/inventory/{$item->id}")->assertOk();

        $this->assertSoftDeleted('inventories', ['id' => $item->id]);
    }
}
