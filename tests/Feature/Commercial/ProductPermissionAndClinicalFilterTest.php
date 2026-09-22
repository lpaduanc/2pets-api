<?php

namespace Tests\Feature\Commercial;

use App\Enums\ImmunizationGroup;
use App\Models\ImmunizationProduct;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consolidação de estoque (docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md):
 * `products` ganhou permissão por verbo (antes a rota inteira era aberta a qualquer usuário
 * autenticado), o seletor clínico por `immunization_product_id`, e a ocultação de custo/margem
 * para quem não gerencia o catálogo (achado do frontend-specialist).
 */
class ProductPermissionAndClinicalFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_clinic_vet_can_list_products_but_not_create_one(): void
    {
        $vet = User::factory()->professional()->create();
        $vet->assignRole('clinic_vet');
        Sanctum::actingAs($vet);

        $this->getJson('/api/professional/products')->assertOk();

        $this->postJson('/api/professional/products', [
            'name' => 'Não deveria criar', 'sku' => 'SKU-BLOQUEADO', 'price' => 10,
        ])->assertForbidden();
    }

    public function test_clinic_vet_never_sees_cost_or_margin_fields(): void
    {
        $vet = User::factory()->professional()->create();
        $vet->assignRole('clinic_vet');
        Product::create([
            'professional_id' => $vet->id,
            'name' => 'Amoxicilina',
            'sku' => 'SKU-COST-1',
            'purpose' => 'consumable',
            'controls_stock' => true,
            'average_cost' => 12.5,
            'price' => 30,
        ]);
        Sanctum::actingAs($vet);

        $response = $this->getJson('/api/professional/products')->assertOk();

        $response->assertJsonMissingPath('data.0.average_cost');
        $response->assertJsonMissingPath('data.0.markup_percent');
        $response->assertJsonPath('data.0.price', 30.0);
    }

    public function test_clinic_owner_sees_cost_and_can_manage_products(): void
    {
        $owner = User::factory()->professional()->create();
        $owner->assignRole('clinic_owner');
        Product::create([
            'professional_id' => $owner->id,
            'name' => 'Amoxicilina',
            'sku' => 'SKU-COST-2',
            'purpose' => 'consumable',
            'controls_stock' => true,
            'average_cost' => 12.5,
            'price' => 30,
        ]);
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/professional/products')->assertOk();

        $response->assertJsonPath('data.0.average_cost', 12.5);
    }

    public function test_receptionist_role_gets_forbidden_on_products_crud(): void
    {
        $staff = User::factory()->professional()->create();
        $staff->assignRole('petshop_staff');
        Sanctum::actingAs($staff);

        // petshop_staff mantém `products.view`/`products.update` (equivalente ao antigo
        // `inventory.view`/`inventory.update`), mas nunca `.create`/`.delete`.
        $this->getJson('/api/professional/products')->assertOk();
        $this->postJson('/api/professional/products', ['name' => 'X', 'sku' => 'SKU-X', 'price' => 1])
            ->assertForbidden();
    }

    public function test_immunization_product_id_filter_returns_only_products_linked_to_that_vaccine(): void
    {
        $owner = User::factory()->professional()->create();
        $owner->assignRole('clinic_owner');

        $immunizationProduct = ImmunizationProduct::create([
            'name' => 'V10',
            'group' => ImmunizationGroup::VACCINE,
            'active' => true,
        ]);

        $linked = Product::create([
            'professional_id' => $owner->id,
            'immunization_product_id' => $immunizationProduct->id,
            'name' => 'Vacina V10 Marca A',
            'sku' => 'SKU-V10-A',
            'purpose' => 'consumable',
            'controls_stock' => true,
            'track_batches' => true,
            'stock_quantity' => 5,
        ]);
        Product::create([
            'professional_id' => $owner->id,
            'name' => 'Ração Premium',
            'sku' => 'SKU-RACAO',
            'purpose' => 'resale',
            'controls_stock' => true,
            'stock_quantity' => 5,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/professional/products?immunization_product_id={$immunizationProduct->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $linked->id);
    }

    public function test_only_in_stock_filter_excludes_zeroed_products(): void
    {
        $owner = User::factory()->professional()->create();
        $owner->assignRole('clinic_owner');

        Product::create([
            'professional_id' => $owner->id, 'name' => 'Com saldo', 'sku' => 'SKU-STOCK-1',
            'purpose' => 'consumable', 'controls_stock' => true, 'stock_quantity' => 3,
        ]);
        Product::create([
            'professional_id' => $owner->id, 'name' => 'Sem saldo', 'sku' => 'SKU-STOCK-2',
            'purpose' => 'consumable', 'controls_stock' => true, 'stock_quantity' => 0,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/professional/products?only_in_stock=1')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Com saldo');
    }

    public function test_product_linked_to_immunization_product_requires_track_batches(): void
    {
        $owner = User::factory()->professional()->create();
        $owner->assignRole('clinic_owner');
        $immunizationProduct = ImmunizationProduct::create([
            'name' => 'V10', 'group' => ImmunizationGroup::VACCINE, 'active' => true,
        ]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/professional/products', [
            'name' => 'Vacina Sem Lote', 'sku' => 'SKU-SEM-LOTE', 'price' => 10,
            'immunization_product_id' => $immunizationProduct->id,
        ])->assertStatus(422)->assertJsonValidationErrors('track_batches');

        $this->postJson('/api/professional/products', [
            'name' => 'Vacina Com Lote', 'sku' => 'SKU-COM-LOTE', 'price' => 10,
            'immunization_product_id' => $immunizationProduct->id, 'track_batches' => true,
        ])->assertCreated();
    }

    public function test_inventory_routes_no_longer_exist(): void
    {
        $owner = User::factory()->professional()->create();
        $owner->assignRole('clinic_owner');
        Sanctum::actingAs($owner);

        $this->getJson('/api/professional/inventory')->assertNotFound();
    }
}
